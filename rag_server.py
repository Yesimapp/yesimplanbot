from fastapi import FastAPI, Request
from pydantic import BaseModel
from sentence_transformers import SentenceTransformer, util

app = FastAPI()
model = SentenceTransformer('all-MiniLM-L6-v2')

# Пример базы (можно потом заменить на загрузку из Laravel)
documents = [
    {"id": 1, "text": "This is a plan for Japan with unlimited data for 30 days."},
    {"id": 2, "text": "eSIM for USA, 10 GB, valid for 15 days."},
    {"id": 3, "text": "European data plan with 5 GB for 7 days."}
]
for doc in documents:
    doc["embedding"] = model.encode(doc["text"], convert_to_tensor=True)

class RAGRequest(BaseModel):
    query: str

@app.post("/rag")
async def rag_search(request: RAGRequest):
    query_embedding = model.encode(request.query, convert_to_tensor=True)
    top_k = 1
    hits = util.semantic_search(query_embedding, [doc["embedding"] for doc in documents], top_k=top_k)[0]
    top_doc = documents[hits[0]["corpus_id"]]
    return {"result": top_doc["text"]}
