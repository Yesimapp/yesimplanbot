from fastapi import FastAPI
from pydantic import BaseModel
from sentence_transformers import SentenceTransformer, util
import os
import databases
import json
import torch

# Настройка кэша HuggingFace
os.environ["HF_HOME"] = "/var/www/html/yesimbot.online/hf_cache"
os.environ["TRANSFORMERS_CACHE"] = "/var/www/html/yesimbot.online/hf_cache"
os.environ["HF_DATASETS_CACHE"] = "/var/www/html/yesimbot.online/hf_cache"

# Подключение к базе MySQL
DATABASE_URL = "mysql+aiomysql://yesim_admin:Pa55w0rd2025@127.0.0.1:3306/yesimbot"
database = databases.Database(DATABASE_URL)

app = FastAPI()
model = SentenceTransformer('all-MiniLM-L6-v2')

class RAGRequest(BaseModel):
    query: str

@app.on_event("startup")
async def startup():
    await database.connect()

@app.on_event("shutdown")
async def shutdown():
    await database.disconnect()

@app.post("/rag")
async def rag_search(request: RAGRequest):
    search_text = request.query.strip()

    # Загружаем все документы с embedding из БД
    query = """
        SELECT plan_name, capacity_info, country, price_info, embedding
        FROM esim_plans
        WHERE plan_name IS NOT NULL AND embedding IS NOT NULL
    """
    rows = await database.fetch_all(query=query)

    documents = []
    for row in rows:
        country_raw = row["country"]
        try:
            countries = json.loads(country_raw)
            if not isinstance(countries, list):
                countries = [str(countries)]
        except Exception:
            countries = [str(country_raw)]

        # Фильтр по стране: проверяем, что запрос есть в списке стран (игнор регистра)
        if not any(search_text.lower() in c.lower() for c in countries):
            continue

        text = " ".join(filter(None, [
            row["plan_name"],
            row["capacity_info"],
            ", ".join(countries),
            row["price_info"],
        ]))

        try:
            emb_list = json.loads(row["embedding"])
            embedding = torch.tensor(emb_list)
        except Exception:
            continue  # если embedding невалидный, пропускаем

        documents.append({
            "text": text,
            "embedding": embedding,
        })

    if not documents:
        return {"result": "No matching plans found."}

    query_embedding = model.encode(search_text, convert_to_tensor=True)

    top_k = min(3, len(documents))
    hits = util.semantic_search(query_embedding, [doc["embedding"] for doc in documents], top_k=top_k)[0]

    results = []
    for hit in hits:
        doc = documents[hit["corpus_id"]]
        results.append({
            "text": doc["text"],
            "score": float(hit["score"]),
        })

    return {
        "query": search_text,
        "results": results,
    }
