FROM python:3.12-slim

WORKDIR /app

RUN apt-get update && apt-get install -y \
    build-essential \
    && rm -rf /var/lib/apt/lists/*

COPY docker/python/requirements.txt .

RUN pip install --upgrade pip \
 && pip install -r requirements.txt \
 && python -m spacy download en_core_web_sm \
 && python -m spacy download ru_core_news_sm

COPY . .

CMD ["uvicorn", "app:app", "--host", "0.0.0.0"]
