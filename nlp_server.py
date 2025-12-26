from dotenv import load_dotenv
load_dotenv()
from fastapi import FastAPI
from pydantic import BaseModel
import spacy
import re
import difflib
import mysql.connector
import json

app = FastAPI()

# Загружаем модели spaCy
nlp_en = spacy.load("en_core_web_sm")
nlp_ru = spacy.load("ru_core_news_sm")

# Подключение к БД
db_config = {
    "host": "127.0.0.1",
    "user": "yesim_admin",
    "password": "Pa55w0rd2025",
    "database": "yesimbot",
    "port": 3306,
}

class InputText(BaseModel):
    text: str

def is_russian(text: str) -> bool:
    return bool(re.search('[а-яА-Я]', text))

def get_countries_from_db():
    """Загрузить из БД все страны с их aliases"""
    conn = mysql.connector.connect(**db_config)
    cursor = conn.cursor(dictionary=True)
    cursor.execute("SELECT name_en, aliases FROM countries WHERE aliases IS NOT NULL")
    rows = cursor.fetchall()
    cursor.close()
    conn.close()
    countries = {}
    for row in rows:
        try:
            aliases = json.loads(row['aliases'])
        except Exception:
            aliases = []
        for alias in aliases:
            countries[alias.lower()] = row['name_en']  # ключ — alias в нижнем регистре, значение — canonical English name
        # Добавим также name_en в качестве алиаса
        countries[row['name_en'].lower()] = row['name_en']
    return countries

def fuzzy_country_search(word: str, countries_dict):
    matches = difflib.get_close_matches(word, countries_dict.keys(), n=1, cutoff=0.7)
    if matches:
        return countries_dict[matches[0]]
    return None

@app.post("/extract")
def extract_info(data: InputText):
    text_lower = data.text.lower()

    # Определяем язык
    doc = nlp_ru(text_lower) if is_russian(text_lower) else nlp_en(text_lower)

    # Ищем количество дней (англ и рус)
    days = None
    match = re.search(r'(\d+)\s*(day|days|день|дня|дней)', text_lower)
    if match:
        days = int(match.group(1))

    country = None

    countries_dict = get_countries_from_db()

    # Ищем страну среди сущностей GPE
    for ent in doc.ents:
        if ent.label_ == "GPE":
            name = ent.text.lower()
            if name in countries_dict:
                country = countries_dict[name]
                break
            else:
                fuzzy_result = fuzzy_country_search(name, countries_dict)
                if fuzzy_result:
                    country = fuzzy_result
                    break

    # Если не нашли через сущности, проверяем токены
    if not country:
        for token in doc:
            lemma = token.lemma_.lower()
            word = token.text.lower()
            if lemma in countries_dict:
                country = countries_dict[lemma]
                break
            elif word in countries_dict:
                country = countries_dict[word]
                break
            else:
                fuzzy_result = fuzzy_country_search(word, countries_dict)
                if fuzzy_result:
                    country = fuzzy_result
                    break

    # Отладка
    print("== DEBUG ==")
    print("INPUT:", data.text)
    print("ENTITIES:", [(ent.text, ent.label_) for ent in doc.ents])
    print("LEMMAS:", [(t.text, t.lemma_) for t in doc])
    print("RESULT:", {"country": country, "days": days})

    return {"country": country, "days": days}
