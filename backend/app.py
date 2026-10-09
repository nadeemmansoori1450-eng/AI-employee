import os
import shutil
from datetime import datetime
from fastapi import FastAPI, UploadFile, File, Form
from fastapi.middleware.cors import CORSMiddleware
from dotenv import load_dotenv
from groq import Groq

# Import existing agents
from agents.email_agent import EmailAgent
from agents.whatsapp_agent import WhatsAppAgent
from agents.calendar_agent import CalendarAgent
from agents.mom_agent import MoMAgent

load_dotenv()

app = FastAPI(title="AI Employee Backend API")

# Enable CORS for Next.js frontend
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Initialize agents
email_agent = EmailAgent()
whatsapp_agent = WhatsAppAgent()
calendar_agent = CalendarAgent()
mom_agent = MoMAgent()
groq_client = Groq(api_key=os.getenv("GROQ_API_KEY"))

# 📌 Store the last generated MoM in memory using a dictionary container (avoids global keyword scope issues)
memory = {"LAST_GENERATED_MOM": None}

@app.get("/")
def read_root():
    return {"status": "AI Employee Backend is running smoothly! 🚀"}

@app.post("/api/chat")
async def chat_endpoint(command: str = Form(...)):
    """Handles text commands from the chat interface with strict intent matching."""
    cmd = command.lower().strip()
    
    try:
        # 1. Send Recorded MoM Intent (Must check before general email/chat)
        if "send" in cmd and ("mom" in cmd or "meeting" in cmd):
            if not memory["LAST_GENERATED_MOM"]:
                return {"response": "❌ No recent meeting MoM found in memory. Please record a meeting first."}
            
            import re
            email_match = re.search(r'[\w\.-]+@[\w\.-]+\.\w+', cmd)
            recipient = email_match.group(0) if email_match else "raza12346350@gmail.com"
            
            current_timestamp = datetime.now().strftime("%Y-%m-%d %H:%M")
            subject = f"Meeting Minutes (MoM) - {current_timestamp}"
            email_body = f"Meeting recorded on: {current_timestamp}\n\n{memory['LAST_GENERATED_MOM']}"
            
            send_result = email_agent.send_email(recipient, subject, email_body)
            return {"response": f"✅ Successfully sent the recorded MoM to **{recipient}**!\n\n*Status:* {send_result}"}

        # 2. Check Unread Emails Intent
        elif any(word in cmd for word in ["unread", "email", "emails", "mail"]) and "send" not in cmd:
            emails = email_agent.fetch_unread_emails(max_results=3)
            if not emails:
                return {"response": "No unread emails found in your inbox."}
            result = email_agent.process_emails_with_groq(emails)
            return {"response": result}
            
        # 3. Send Custom Email Intent
        elif "send" in cmd or "@" in cmd:
            import re
            email_match = re.search(r'[\w\.-]+@[\w\.-]+\.\w+', cmd)
            recipient = email_match.group(0) if email_match else "raza12346350@gmail.com"
            
            topic = "Thank you / Following up"
            if "thanks" in cmd or "thank" in cmd:
                topic = "Thank you for connecting"
            elif "follow" in cmd:
                topic = "Project Follow-up"
                
            prompt = f"Write a professional email about: '{topic}'. Keep it polite, clear, and structured with a subject line and body."
            response = email_agent.client.chat.completions.create(
                model="openai/gpt-oss-120b",
                messages=[
                    {"role": "system", "content": "You are a professional email writing assistant."},
                    {"role": "user", "content": prompt}
                ],
                temperature=0.3
            )
            generated_email = response.choices[0].message.content
            subject = f"Message / Update: {topic}"
            
            send_result = email_agent.send_email(recipient, subject, generated_email)
            return {"response": f"✅ Email successfully sent to **{recipient}**!\n\n**Subject:** {subject}\n\n**Content Preview:**\n{generated_email}\n\n*Status:* {send_result}"}
            
        # 4. Calendar Intent
        elif "calendar" in cmd or "event" in cmd:
            events = calendar_agent.list_upcoming_events(max_results=5)
            if not events:
                return {"response": "No upcoming calendar events found."}
            events_text = "\n".join([f"- **{e['summary']}** at {e['start']}" for e in events])
            return {"response": f"### Upcoming Calendar Events\n{events_text}"}
            
        # 5. WhatsApp Intent
        elif "whatsapp" in cmd:
            wa_response = whatsapp_agent.process_client_message("Hi, status of project delivery and pricing details.")
            return {"response": wa_response}
            
        # 6. MoM / Meeting Intent
        elif any(kw in cmd for kw in ["mom", "meeting", "record meeting", "record metting", "meeting record"]):
            sample_notes = "Meeting with client Rahul. Discussed Next.js dashboard layout issues and API fetching errors. Rahul wants the admin panel ready by Friday."
            mom_error_check = mom_agent.generate_mom(sample_notes)
            memory["LAST_GENERATED_MOM"] = mom_error_check
            return {
                "response": f"### 🎙️ Live Meeting Record & MoM Generated\n\n{mom_error_check}\n\n*(Tip: To record actual audio, click the **'Stop Meeting Recording'** button at the top when you finish speaking!)*"
            }
            
        else:
            completion = groq_client.chat.completions.create(
                model="openai/gpt-oss-120b",
                messages=[
                    {"role": "system", "content": "You are a helpful AI Employee assistant."},
                    {"role": "user", "content": command}
                ]
            )
            return {"response": completion.choices[0].message.content}
            
    except Exception as e:
        return {"response": f"Error executing command: {str(e)}"}

@app.post("/api/voice-transcribe")
async def voice_transcribe(file: UploadFile = File(...)):
    """Handles audio file upload from frontend microphone and transcribes via Groq Whisper."""
    temp_file = f"temp_{file.filename}"
    with open(temp_file, "wb") as buffer:
        shutil.copyfileobj(file.file, buffer)
        
    try:
        with open(temp_file, "rb") as audio_file:
            transcription = groq_client.audio.transcriptions.create(
                file=(temp_file, audio_file.read()),
                model="whisper-large-v3",
                response_format="text"
            )
        os.remove(temp_file)
        return {"transcript": transcription}
    except Exception as e:
        if os.path.exists(temp_file):
            os.remove(temp_file)
        return {"error": str(e)}

@app.post("/api/record-meeting-audio")
async def record_meeting_audio(file: UploadFile = File(...)):
    temp_file = f"meeting_{file.filename}"
    with open(temp_file, "wb") as buffer:
        shutil.copyfileobj(file.file, buffer)
        
    try:
        with open(temp_file, "rb") as audio_file:
            transcript = groq_client.audio.transcriptions.create(
                file=(temp_file, audio_file.read()),
                model="whisper-large-v3",
                response_format="text"
            )
        
        current_timestamp = datetime.now().strftime("%Y-%m-%d %H:%M")
        meeting_notes = f"Meeting Date & Time: {current_timestamp}\nTranscript: {transcript}"
        mom_result = mom_agent.generate_mom(meeting_notes)
        
        # Save to memory container
        memory["LAST_GENERATED_MOM"] = mom_result
        
        os.remove(temp_file)
        return {
            "transcript": transcript,
            "mom": mom_result
        }
    except Exception as e:
        if os.path.exists(temp_file):
            os.remove(temp_file)
        return {"error": str(e)}