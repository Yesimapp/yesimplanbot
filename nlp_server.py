from fastapi import FastAPI
from pydantic import BaseModel
import spacy
import re
import difflib

app = FastAPI()

# Загрузите модель командой:
# python3 -m spacy download en_core_web_sm
nlp = spacy.load("en_core_web_sm")

COUNTRY_ALIASES = {
    "turkey": "Turkey",
    "japan": "Japan",
    "ukraine": "Ukraine",
    "italy": "Italy",
    "france": "France",
    "germany": "Germany",
    "poland": "Poland",
    "united states": "United States",
    "usa": "United States",
    "spain": "Spain",
    "vietnam": "Vietnam",
    "thailand": "Thailand",
    "india": "India",
    "egypt": "Egypt",
    "georgia": "Georgia",
    "kazakhstan": "Kazakhstan",
    "czech republic": "Czech Republic",
    "switzerland": "Switzerland",
    "austria": "Austria",
    "belgium": "Belgium",
    "bulgaria": "Bulgaria",
    "canada": "Canada",
    "croatia": "Croatia",
    "cyprus": "Cyprus",
    "estonia": "Estonia",
    "hungary": "Hungary",
    "ireland": "Ireland",
    "israel": "Israel",
    "latvia": "Latvia",
    "lithuania": "Lithuania",
    "luxembourg": "Luxembourg",
    "malta": "Malta",
    "montenegro": "Montenegro",
    "netherlands": "Netherlands",
    "north macedonia": "North Macedonia",
    "portugal": "Portugal",
    "romania": "Romania",
    "russia": "Russia",
    "serbia": "Serbia",
    "slovakia": "Slovakia",
    "slovenia": "Slovenia",
    "south africa": "South Africa",
    "united arab emirates": "United Arab Emirates",
    "uk": "United Kingdom",
    "united kingdom": "United Kingdom",
    "hong kong": "Hong Kong",
}

def fuzzy_country_search(word: str):
    matches = difflib.get_close_matches(word, COUNTRY_ALIASES.keys(), n=1, cutoff=0.7)
    if matches:
        return COUNTRY_ALIASES[matches[0]]
    return None

class InputText(BaseModel):
    text: str

@app.post("/extract")
def extract_info(data: InputText):
    text_lower = data.text.lower()
    doc = nlp(text_lower)

    # Ищем количество дней
    days = None
    match = re.search(r'(\d+)\s*(day|days)', text_lower)
    if match:
        days = int(match.group(1))

    country = None

    # Ищем среди сущностей GPE
    for ent in doc.ents:
        if ent.label_ == "GPE":
            name = ent.text.lower()
            if name in COUNTRY_ALIASES:
                country = COUNTRY_ALIASES[name]
                break
            else:
                fuzzy_result = fuzzy_country_search(name)
                if fuzzy_result:
                    country = fuzzy_result
                    break

    # Если не нашли, проверяем токены
    if not country:
        for token in doc:
            lemma = token.lemma_.lower()
            word = token.text.lower()

            if lemma in COUNTRY_ALIASES:
                country = COUNTRY_ALIASES[lemma]
                break
            elif word in COUNTRY_ALIASES:
                country = COUNTRY_ALIASES[word]
                break
            else:
                fuzzy_result = fuzzy_country_search(word)
                if fuzzy_result:
                    country = fuzzy_result
                    break

    # Отладка (можно убрать)
    print("== DEBUG ==")
    print("INPUT:", data.text)
    print("ENTITIES:", [(ent.text, ent.label_) for ent in doc.ents])
    print("LEMMAS:", [(t.text, t.lemma_) for t in doc])
    print("RESULT:", {"country": country, "days": days})

    return {"country": country, "days": days}
