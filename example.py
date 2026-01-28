import hashlib
import os
import pickle
import shutil
import time

# Suppress tokenizers parallelism warning
os.environ["TOKENIZERS_PARALLELISM"] = "false"

import joblib
import pandas as pd
import torch
import utils
from dotenv import load_dotenv
from fastapi import Depends, FastAPI, File, HTTPException, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse, JSONResponse
from fastapi.security import HTTPBasic, HTTPBasicCredentials
from fastapi.staticfiles import StaticFiles
from openai import OpenAI
from starlette.status import HTTP_401_UNAUTHORIZED

load_dotenv()

app = FastAPI()
CAT0 = "unlikely"
CAT1 = "possible"
CAT2 = "likely"
CAT3 = "very likely"
DEFAULT_MODEL = "tngtech/deepseek-r1t2-chimera:free"

# Enable CORS for frontend-backend communication
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

security = HTTPBasic()

# Dummy credentials for demonstration purposes
USERNAME = "father"
PASSWORD = "christmas"

def verify_credentials(credentials: HTTPBasicCredentials = Depends(security)):
    correct_username = credentials.username == USERNAME
    correct_password = credentials.password == PASSWORD
    if not (correct_username and correct_password):
        raise HTTPException(
            status_code=HTTP_401_UNAUTHORIZED,
            detail="Incorrect username or password",
            headers={"WWW-Authenticate": "Basic"},
        )

# Absolute paths for directories
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
temp_dir = os.path.join(BASE_DIR, "temp_uploads")
# Make output persist outside temp_dir so cleanup doesn't remove files we link to
output_dir = os.path.join(BASE_DIR, "output")
MODEL_PATH = os.path.join(BASE_DIR, "models", "CC_BERT", "CC_model_detect")
CLAUSE_FOLDER = os.path.join(BASE_DIR, "data", "cleaned_content")
CLAUSE_HTML = os.path.join(BASE_DIR, "data", "clause_boxes")
CLAUSE_TAGS = os.path.join(BASE_DIR, "data", "clause_tags_with_clusters.xlsx")
EMISSION_INDICATORS = os.path.join(BASE_DIR, "data", "full_emissions_table_2.csv")
INDEX_PATH = os.path.join(BASE_DIR, "provocotype-1", "index.htm")
ALT_INDEX_PATH = os.path.join(BASE_DIR, "provocotype-1", "index2.htm")
CLUSTERING_MODEL = os.path.join(BASE_DIR, 'models', 'clustering_model.pkl')
UMAP_MODEL = os.path.join(BASE_DIR, 'models', 'umap_model.pkl')

app.mount(
    "/assets",
    StaticFiles(directory=os.path.join(BASE_DIR, "provocotype-1", "assets")),
    name="assets",
)

os.makedirs(output_dir, exist_ok=True)
app.mount("/output", StaticFiles(directory=output_dir), name="output")

print("[INFO] Loading model and data...")
tokenizer, d_model, c_model, names, docs, final_df, child_names, name_to_child, name_to_url = utils.getting_started(MODEL_PATH, CLAUSE_FOLDER, CLAUSE_HTML)
clause_tags = pd.read_excel(CLAUSE_TAGS)
emission_df = pd.read_csv(EMISSION_INDICATORS)
with open(CLUSTERING_MODEL, 'rb') as f:
    clf = pickle.load(f)
umap_model = joblib.load(UMAP_MODEL)
device = torch.device("cpu")

# Simple in-memory cache for embeddings (keyed by file content hash)
# This allows process_contract and find_clauses to share embeddings
embedding_cache = {}

# --- OpenAI / OpenRouter Setup ---
OPENROUTER_API_KEY = os.getenv("OPENROUTER_API_KEY")
OPENROUTER_MODEL = os.getenv("OPENROUTER_MODEL", DEFAULT_MODEL)  
if not OPENROUTER_API_KEY:
    print("[WARNING] OPENROUTER_API_KEY not found in environment variables. API calls will fail.")
else:
    print(f"[INFO] OpenRouter API key found (starts with: {OPENROUTER_API_KEY[:10]}...)")
    print(f"[INFO] Using OpenRouter model: {OPENROUTER_MODEL}")

client = OpenAI(
    api_key=OPENROUTER_API_KEY,
    base_url="https://openrouter.ai/api/v1")

@app.post("/process/")
async def process_contract(file: UploadFile):
    """
    Endpoint to process a contract file or folder.
    """
    # Cleanup and recreate temp directories
    if os.path.exists(temp_dir):
        shutil.rmtree(temp_dir)
    os.makedirs(temp_dir, exist_ok=True)
    os.makedirs(output_dir, exist_ok=True)
    
    # Check validity and open file
    allowed_extensions = ['.txt', '.pdf', '.docx', '.doc', '.md']
    if not any(file.filename.lower().endswith(ext) for ext in allowed_extensions):
        return JSONResponse(
            content={
                "error": f"Only {', '.join(allowed_extensions)} files are supported."
            },
            status_code=400,
        )

    # Read file content once
    file_content = await file.read()
    
    # Save original file first (for MarkItDown to process)
    original_file_path = os.path.join(temp_dir, file.filename)
    with open(original_file_path, "wb") as f:
        f.write(file_content)
    
    # Convert file to text for processing
    try:
        full_contract_text = utils.convert_file_to_text(file_content, file.filename)
    except Exception as e:
        return JSONResponse(
            content={
                "error": f"Error converting file to text: {str(e)}"
            },
            status_code=400,
        )
    
    # Save as .txt file for load_unlabelled_contract to process
    base_filename = os.path.splitext(file.filename)[0]
    txt_filename = f"{base_filename}.txt"
    txt_file_path = os.path.join(temp_dir, txt_filename)
    with open(txt_file_path, "w", encoding="utf-8") as f:
        f.write(full_contract_text)
    
    # Convert to markdown using MarkItDown for display
    markdown_content = None
    try:
        from markitdown import MarkItDown
        
        # MarkItDown needs a file path - use the original file we saved
        md = MarkItDown()
        result = md.convert(original_file_path)
        
        # MarkItDown returns a ConvertResult object with .text_content attribute
        if result and hasattr(result, 'text_content'):
            markdown_content = result.text_content
        elif result and hasattr(result, 'markdown'):
            markdown_content = result.markdown
        elif result:
            markdown_content = str(result)
            if markdown_content == "None" or not markdown_content.strip():
                markdown_content = None
        
        # Validate we got actual content
        if not markdown_content or str(markdown_content).strip() == "":
            markdown_content = None
    except Exception as e:
        print(f"Warning: MarkItDown conversion failed: {str(e)}")
        markdown_content = None
    # Embed the full contract text once (will be reused by find_clauses)
    # Cache it using file content hash as key
    file_hash = hashlib.md5(file_content).hexdigest()
    full_contract_embedding = utils.encode_text(full_contract_text, tokenizer, c_model)
    embedding_cache[file_hash] = {
        'embedding': full_contract_embedding,
        'text': full_contract_text
    }

    processed_contracts = utils.load_unlabelled_contract(temp_dir)
    texts = processed_contracts["text"].tolist()

    # Model predictions
    results, _ = utils.predict_climatebert(texts, tokenizer, device, d_model)
    result_df, _ = utils.create_result_df(results, processed_contracts)
    
    # Extract sentences that should be highlighted (prediction=1 or keyword_match)
    highlighted_sentences = result_df[
        (result_df['prediction'] == 1) | result_df['contains_climate_keyword']
    ]['sentence'].tolist()
    
    # Render document: use markdown if available, otherwise fallback to txt highlighting
    if markdown_content and str(markdown_content).strip():
        try:
            highlighted_output = utils.render_markdown_with_highlights(
                markdown_content,
                full_contract_text,
                highlighted_sentences
            )
        except Exception as e:
            print(f"Warning: MarkItDown rendering failed: {str(e)}. Using fallback method.")
            highlighted_output = utils.highlight_climate_content(result_df)
    else:
        highlighted_output = utils.highlight_climate_content(result_df)
    
    # Save into output directory with timestamp
    timestamp = int(time.time())
    filename = f"highlighted_output_{timestamp}.html"
    filepath = os.path.join(output_dir, filename)
    utils.save_file(filepath, highlighted_output)
    print(f"Saved highlighted output to: {filepath}")

    contract_df = utils.create_contract_df(
        result_df, processed_contracts, labelled=False
    )

    zero, one, two, three = utils.create_threshold_buckets(contract_df)

    result = utils.print_single(
        zero, one, two, three, return_result=True
    )
    response = {
        "classification": result,
        "highlighted_output_url": f"/output/{filename}",
        "bucket_labels": {
            "cat0": CAT0,
            "cat1": CAT1,
            "cat2": CAT2,
            "cat3": CAT3
        }
    }

    # Cleanup
    shutil.rmtree(temp_dir, ignore_errors=True)
    os.makedirs(
        output_dir, exist_ok=True
    )  # Recreate the output directory after cleanup
    return JSONResponse(content=response)

@app.post("/find_clauses/")
async def find_clauses(file: UploadFile = File(...)):
    # Check validity
    allowed_extensions = ['.txt', '.pdf', '.docx', '.doc', '.md']
    if not any(file.filename.lower().endswith(ext) for ext in allowed_extensions):
        return JSONResponse(
            content={
                "error": f"Only {', '.join(allowed_extensions)} files are supported."
            },
            status_code=400,
        )
    
    content = await file.read()
    
    # Convert file to text
    try:
        query_text = utils.convert_file_to_text(content, file.filename)
    except Exception as e:
        return JSONResponse(
            content={
                "error": f"Error converting file to text: {str(e)}"
            },
            status_code=400,
        )
    
    # Check if we have a cached embedding from process_contract
    file_hash = hashlib.md5(content).hexdigest()
    if file_hash in embedding_cache:
        # Reuse the embedding from process_contract
        query_embedding = embedding_cache[file_hash]['embedding']
        # Clean up cache entry after use
        del embedding_cache[file_hash]
    else:
        # Embed the query text (used by both perform_cluster and get_embedding_matches_subset)
        query_embedding = utils.encode_text(query_text, tokenizer, c_model)
    
    subset_docs, subset_names, _ = utils.perform_cluster(clf, query_embedding, tokenizer, c_model, clause_tags, umap_model, embed = False)
    bow_results = utils.find_top_similar_bow(target_doc=query_text, documents=docs, file_names=names, similarity_threshold=0.1, k =20)
    top_docs = bow_results["Documents"]
    top_names = bow_results["Top_Matches"]
    top_names_bow, _, top_texts_bow = utils.get_embedding_matches_subset(query_embedding, top_docs, top_names, tokenizer, c_model, k=5)
    
    ## putting them into a shared dataframe 
    df_cluster = pd.DataFrame({
        "text": subset_docs,
        "source_name": subset_names,
        "matched_by": ["cluster"] * len(subset_names)
    })

    df_bow = pd.DataFrame({
        "text": top_texts_bow,
        "source_name": top_names_bow,
        "matched_by": ["bow"] * len(top_texts_bow)
    }).head(5)  # Top 5 from BOW
    combined_df = pd.concat([df_cluster, df_bow], ignore_index=True)
    
    query_text_short = query_text[:1000]
    
    messages = [
    {
        "role": "system",
        "content": "You are a legal AI assistant that helps review and select climate-aligned clauses for the uploaded document. You can only select from those clauses provided to you. We are trying to help the writers of the document integrate climate-aligned language."
    },
    {
        "role": "user",
        "content": f"Here's the contract:\n\n{query_text_short.strip()}\n\nI will send you some clauses next. For now, just confirm you have read the contract and are ready to receive the clauses. A short summary of the content of the contract would be fine."
    }
    ]

    response = client.chat.completions.create(
        model=OPENROUTER_MODEL, 
        messages= messages,
        temperature=0,
        max_tokens=1000
    )

    assistant_reply_1 = response.choices[0].message.content
    messages.append({"role": "assistant", "content": assistant_reply_1})

    clause_block = "Here are the clauses:\n\n"

    for i, row in combined_df.iterrows():
        clause_block += (
            f"Clause {i+1}\n"
            f"Name: {row['source_name']}\n"
            f"Method: {row['matched_by']}\n"
            f"Full Text:\n{row['text']}\n\n"
        )

    clause_block += '''Select the clauses from the list that best align with the contract. 
    It is really important that you answer this consistently and the same way every time. If I upload the same contract against, I expect to see the same answer. 
    
    This is a two step process. 
    
    Step 1: Binary select the clauses that are a good fit for the contract. Go through one by one and remember which ones you selected as a potential fit. As a rule of thumb, give no fewer than 3 and no more than 7. If there is good reason, you can do fewer or more.
    Step 2: Go through those that you have selected as a fit and provide reasoning. Feel free to reconsider whether they are a fit once you go through them again. 
    
    Before you being, read the rules below. They should guide you on both steps. 
    
    Follow these rules:

    1. Your response must be a JSON of exactly as many objects as there are clauses you have selected as a fit, each with the keys "Clause Name" and "Reasoning".
    3. Only select from the clauses provided — do not invent new ones.
    4. Remember the contract’s **content and purpose**. Their goal is likely not to reduce their emissions, but to meet other business or legal needs. We are telling them where they can inject climate-aligned language into the existing contract but the existing contract and its goals are the most important consideration.
    5. Pay close attention to what the contract is **doing** — the transaction type, structure, and key obligations — not just who the parties are or what sector they operate in.
    - Clauses must fit the **actual function and scope** of the contract.
    - For example, do not recommend a clause about land access if the contract is about software licensing.
    - Another example: do not recommend a clause about insurance if the contract is establishing a joint venture.
    6. Consider the relationship between the parties (e.g. supplier–customer, insurer–insured, JV partners).
    - If a clause assumes a different relationship, only suggest it if it can **realistically be adapted**, and explain how.
    7. You may include a clause that is not a perfect match if:
    - It serves a similar **legal or operational function**, and
    - You clearly explain how it could be adapted to the contract context.
    8. Do not recommend clauses that clearly mismatch the contract’s type, scope, or parties.
    9. Avoid redundancy. If the contract already addresses a topic (e.g. dispute resolution), only suggest a clause on that topic if it adds clear value.

    Focus on legal function, contextual fit, and the actual mechanics of the contract. You are recommending **starting points** — plausible clauses the user could adapt.'''

    messages.append({"role": "user", "content": clause_block})

    response = client.chat.completions.create(
        model=OPENROUTER_MODEL,  
        messages= messages,
        temperature=0,
    )

    response_text = response.choices[0].message.content
    df_response = utils.parse_response(response_text)
    
    # Check if parsing was successful
    if df_response is None:
        print("Failed to parse LLM response, returning empty recommendations")
        return {
            "matches": []
        }
    
    missing = []
    for clause in df_response["Clause Name"]:
        target = clause + ".txt"
        # try to find at least one close match in your names list
        close = utils.get_close_matches(target, names, n=1, cutoff=0.8)
        if not close:
            missing.append(clause)
            
    if missing:
        print(f"[WARNING] Clauses not found: {missing}")
        # tack on the assistant’s bad output, then our correction prompt
        messages.append({"role":"assistant","content":response_text})
        messages.append({
            "role":"user",
            "content": (
                "One of the clauses you recommended "
                f"({', '.join(missing)}) was not in the provided set. "
                "Do not hallucinate: only pick from the list I gave you, "
                "and please try again."
            )
        })

        # re-call the LLM
        retry = client.chat.completions.create(
            model=DEFAULT_MODEL,
            messages=messages,
            temperature=0)
        response_text = retry.choices[0].message.content
        df_response = utils.parse_response(response_text)
        
        # Check if retry parsing was successful
        if df_response is None:
            print("Failed to parse retry LLM response, returning empty recommendations")
            return {
                "matches": []
            }
        
    #find the clause names in the emission_df
    df_response = utils.get_emission_label(df_response, emission_df)

    # Build matches
    matches = []
    for _, row in df_response.iterrows():
        clause_name = row["Clause Name"].replace(".txt", "")
        matches.append({
            "name": clause_name,
            "child_name": name_to_child.get(clause_name, ""),
            "clause_url": name_to_url.get(clause_name, ""),
            "reason": row["Reasoning"],
            "emissions_sources": utils.parse_emissions_sources(row.get("combined_labels"))
        })

    return {"matches": matches}

# --- Serve Frontend ---
@app.get("/", response_class=FileResponse)
def read_root(credentials: HTTPBasicCredentials = Depends(verify_credentials)):
    if not os.path.exists(INDEX_PATH):
        raise RuntimeError(f"{INDEX_PATH} not found")
    return FileResponse(INDEX_PATH, media_type="text/html")

# Optional: serve the secondary frontend directly
@app.get("/index2.htm", response_class=FileResponse)
def read_index2_htm(credentials: HTTPBasicCredentials = Depends(verify_credentials)):
    if not os.path.exists(ALT_INDEX_PATH):
        raise RuntimeError(f"{ALT_INDEX_PATH} not found")
    return FileResponse(ALT_INDEX_PATH, media_type="text/html")

@app.get("/index2", response_class=FileResponse)
def read_index2(credentials: HTTPBasicCredentials = Depends(verify_credentials)):
    if not os.path.exists(ALT_INDEX_PATH):
        raise RuntimeError(f"{ALT_INDEX_PATH} not found")
    return FileResponse(ALT_INDEX_PATH, media_type="text/html")

# --- Run with Uvicorn ---
if __name__ == "__main__":
    import uvicorn
    uvicorn.run("app:app", host="0.0.0.0", port=8000)