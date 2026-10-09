import os
import datetime
import pickle
from google.auth.transport.requests import Request
from google_auth_oauthlib.flow import InstalledAppFlow
from googleapiclient.discovery import build
from groq import Groq
from dotenv import load_dotenv

load_dotenv()

# Scope for reading and writing calendar events
SCOPES = ['https://www.googleapis.com/auth/calendar']

class CalendarAgent:
    def __init__(self, credentials_path="credentials/client_secret.json", token_path="credentials/calendar_token.pickle"):
        self.credentials_path = credentials_path
        self.token_path = token_path
        self.service = self._authenticate_calendar()
        self.client = Groq(api_key=os.getenv("GROQ_API_KEY"))

    def _authenticate_calendar(self):
        """Authenticates with Google Calendar API via OAuth 2.0."""
        creds = None
        if os.path.exists(self.token_path):
            with open(self.token_path, 'rb') as token:
                creds = pickle.load(token)
        
        if not creds or not creds.valid:
            if creds and creds.expired and creds.refresh_token:
                creds.refresh(Request())
            else:
                flow = InstalledAppFlow.from_client_secrets_file(
                    self.credentials_path, SCOPES)
                creds = flow.run_local_server(port=0)
            
            with open(self.token_path, 'wb') as token:
                pickle.dump(creds, token)

        return build('calendar', 'v3', credentials=creds)

    def list_upcoming_events(self, max_results=5):
        """Fetches upcoming events from the user's primary calendar."""
        now = datetime.datetime.utcnow().isoformat() + 'Z'  # 'Z' indicates UTC
        events_result = self.service.events().list(
            calendarId='primary', timeMin=now,
            maxResults=max_results, singleEvents=True,
            orderBy='startTime'
        ).execute()
        
        events = events_result.get('items', [])
        event_list = []

        for event in events:
            start = event['start'].get('dateTime', event['start'].get('date'))
            summary = event.get('summary', 'No Title')
            event_list.append({"start": start, "summary": summary})
            
        return event_list

    def parse_scheduling_intent(self, user_request):
        """Uses Groq LLM to extract event details (title, time) from natural language."""
        prompt = f"""
        Extract the event title, start time, and duration from the following user request. Return the response in a clear text format.
        Request: "{user_request}"
        """
        response = self.client.chat.completions.create(
            model="openai/gpt-oss-120b",
            messages=[
                {"role": "system", "content": "You are a precise calendar scheduling assistant."},
                {"role": "user", "content": prompt}
            ],
            temperature=0.2
        )
        return response.choices[0].message.content