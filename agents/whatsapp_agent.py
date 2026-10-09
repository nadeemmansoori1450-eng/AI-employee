import os
from groq import Groq
from dotenv import load_dotenv

load_dotenv()

class WhatsAppAgent:
    def __init__(self):
        self.client = Groq(api_key=os.getenv("GROQ_API_KEY"))

    def process_client_message(self, client_message):
        """Processes incoming WhatsApp client queries and formulates a concise, polite response."""
        prompt = f"""
        You are a fast and polite customer service representative on WhatsApp. 
        Formulate a concise, professional, and helpful response to the following client message:

        Client Message: "{client_message}"
        """

        response = self.client.chat.completions.create(
            model="openai/gpt-oss-120b",
            messages=[
                {"role": "system", "content": "You are a polite and swift WhatsApp customer support assistant."},
                {"role": "user", "content": prompt}
            ],
            temperature=0.3
        )
        return response.choices[0].message.content