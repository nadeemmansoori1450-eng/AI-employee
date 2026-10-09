import os
import pickle
import base64
from email.message import EmailMessage
from google.auth.transport.requests import Request
from google_auth_oauthlib.flow import InstalledAppFlow
from googleapiclient.discovery import build
from groq import Groq
from dotenv import load_dotenv

load_dotenv()

# Updated SCOPES to allow reading and sending emails
SCOPES = [
    'https://www.googleapis.com/auth/gmail.readonly',
    'https://www.googleapis.com/auth/gmail.send'
]

class EmailAgent:
    def __init__(self, credentials_path="credentials/client_secret.json", token_path="credentials/token.pickle"):
        self.credentials_path = credentials_path
        self.token_path = token_path
        self.service = self._authenticate_gmail()
        self.client = Groq(api_key=os.getenv("GROQ_API_KEY"))

    def _authenticate_gmail(self):
        """Authenticates with Gmail API via OAuth 2.0."""
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

        return build('gmail', 'v1', credentials=creds)

    def fetch_unread_emails(self, max_results=5):
        """Fetches unread emails from the primary inbox."""
        results = self.service.users().messages().list(
            userId='me', q='is:unread label:INBOX', maxResults=max_results
        ).execute()
        
        messages = results.get('messages', [])
        email_data = []

        for msg in messages:
            msg_detail = self.service.users().messages().get(userId='me', id=msg['id']).execute()
            headers = msg_detail['payload']['headers']
            
            subject = next((h['value'] for h in headers if h['name'] == 'Subject'), 'No Subject')
            sender = next((h['value'] for h in headers if h['name'] == 'From'), 'Unknown Sender')
            snippet = msg_detail.get('snippet', '')

            email_data.append({
                "sender": sender,
                "subject": subject,
                "snippet": snippet
            })
            
        return email_data

    def process_emails_with_groq(self, emails):
        """Sends fetched emails to Groq for categorization and response drafting."""
        if not emails:
            return "No unread emails found in your inbox."

        prompt = f"""
        You are an expert Executive Email Manager. Analyze the following unread emails and categorize each into Urgent, Follow-up, or General, and provide a recommended professional reply draft for each.

        Emails:
        {emails}
        """

        response = self.client.chat.completions.create(
            model="openai/gpt-oss-120b",
            messages=[
                {"role": "system", "content": "You are a professional email assistant."},
                {"role": "user", "content": prompt}
            ],
            temperature=0.2
        )
        return response.choices[0].message.content

    def send_email(self, to_email, subject, body_text):
        """Sends an email using the Gmail API."""
        try:
            message = EmailMessage()
            message.set_content(body_text)
            message['To'] = to_email
            message['Subject'] = subject
            message['From'] = 'me'

            encoded_message = base64.urlsafe_b64encode(message.as_bytes()).decode()
            create_message = {'raw': encoded_message}

            send_message = self.service.users().messages().send(
                userId="me", body=create_message
            ).execute()
            
            return f"Email successfully sent! Message ID: {send_message['id']}"
        except Exception as e:
            return f"Failed to send email: {e}"