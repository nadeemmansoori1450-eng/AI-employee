import os
import re
from datetime import datetime
from dotenv import load_dotenv
from agents.email_agent import EmailAgent
from agents.whatsapp_agent import WhatsAppAgent
from agents.calendar_agent import CalendarAgent
from agents.mom_agent import MoMAgent
from agents.recorder_agent import RecorderAgent
from rich.console import Console
from rich.panel import Panel
from rich.markdown import Markdown

load_dotenv()
console = Console()

def get_time_greeting():
    hour = datetime.now().hour
    if 5 <= hour < 12:
        return "Good Morning"
    elif 12 <= hour < 17:
        return "Good Afternoon"
    else:
        return "Good Evening"

def main():
    email_agent = EmailAgent()
    whatsapp_agent = WhatsAppAgent()
    calendar_agent = CalendarAgent()
    mom_agent = MoMAgent()
    recorder_agent = RecorderAgent()

    last_generated_mom = None
    greeting = get_time_greeting()
    
    console.print(f"\n[bold cyan]🤖 AI Employee Chat Assistant[/bold cyan]")
    console.print(f"[italic green]{greeting}! Type your command OR type 'mic' to speak your command.[/italic green]\n")

    while True:
        raw_input_val = input("👉 You (Type command or 'mic' to speak): ").strip()

        if not raw_input_val:
            continue

        # Handle Microphone Input Option
        if raw_input_val.lower() == "mic":
            console.print("[bold yellow]🎙️ Microphone active... Speak your command now (Press ENTER to stop):[/bold yellow]")
            try:
                user_input = recorder_agent.record_and_transcribe().strip().lower()
                console.print(f"[bold cyan]🗣️ Recognized Voice Command:[/bold cyan] {user_input}")
            except Exception as e:
                console.print(f"[bold red]Voice Recording Error: {e}[/bold red]")
                continue
        else:
            user_input = raw_input_val.lower()

        if "exit" in user_input or "quit" in user_input or user_input == "6":
            console.print("[bold cyan]AI Assistant: Goodbye! Have a great day ahead. 👋[/bold cyan]")
            break

        # 1. Live Meeting Recording & Auto MoM
        elif "record" in user_input or "meeting recording" in user_input:
            try:
                transcript = recorder_agent.record_and_transcribe()
                
                console.print("\n[bold cyan]--- Meeting Transcript ---[/bold cyan]")
                console.print(transcript)
                console.print("[bold cyan]--------------------------[/bold cyan]")

                console.print("[bold yellow]AI Assistant: Generating Minutes of Meeting (MoM)...[/bold yellow]")
                current_timestamp = datetime.now().strftime("%Y-%m-%d %H:%M")
                
                meeting_notes = f"Meeting Date & Time: {current_timestamp}\nTranscript: {transcript}"
                mom_result = mom_agent.generate_mom(meeting_notes)
                
                last_generated_mom = mom_result
                
                console.print(Panel(Markdown(mom_result), title="[bold cyan]📝 Generated MoM Summary[/bold cyan]", border_style="yellow"))

                send_email_choice = input("   ↳ Do you want to email this MoM with date/time? (y/n): ").strip().lower()
                if send_email_choice == 'y':
                    recipient = input("   ↳ Enter recipient email address: ").strip()
                    if not recipient:
                        recipient = "raza12346350@gmail.com"
                    
                    subject = f"Meeting Minutes (MoM) - {current_timestamp}"
                    email_body = f"Meeting recorded on: {current_timestamp}\n\n{mom_result}"
                    
                    result = email_agent.send_email(recipient, subject, email_body)
                    console.print(f"[bold green]AI Assistant: {result}[/bold green]")
                else:
                    console.print("[bold yellow]AI Assistant: MoM saved in memory. You can say 'send this mom' anytime.[/bold yellow]")

            except Exception as e:
                console.print(f"[bold red]Recording/MoM Error: {e}[/bold red]")

        # 2. Send the exact recorded MoM if user asks for it later
        elif "send this mom" in user_input or ("mom" in user_input and "send" in user_input):
            if not last_generated_mom:
                console.print("[bold red]AI Assistant: No recent meeting MoM found in memory. Please record a meeting first.[/bold red]")
            else:
                email_match = re.search(r'[\w\.-]+@[\w\.-]+\.\w+', user_input)
                recipient = email_match.group(0) if email_match else input("   ↳ Enter recipient email address: ").strip()
                if not recipient:
                    recipient = "raza12346350@gmail.com"
                
                current_timestamp = datetime.now().strftime("%Y-%m-%d %H:%M")
                subject = f"Meeting Minutes (MoM) - {current_timestamp}"
                email_body = f"Meeting recorded on: {current_timestamp}\n\n{last_generated_mom}"
                
                console.print(f"[bold yellow]AI Assistant: Sending the recorded MoM to {recipient}...[/bold yellow]")
                result = email_agent.send_email(recipient, subject, email_body)
                console.print(f"[bold green]AI Assistant: {result}[/bold green]")

        # 3. Send Custom/Follow-up Email
        elif "send" in user_input or "@" in user_input or user_input == "2":
            email_match = re.search(r'[\w\.-]+@[\w\.-]+\.\w+', user_input)
            recipient = email_match.group(0) if email_match else input("   ↳ Enter recipient email address: ").strip()
            
            topic = input("   ↳ Enter what the email should be about (e.g., thanks, project update): ").strip()
            if not topic:
                topic = "Thank you / Following up"
            
            console.print(f"[bold yellow]AI Assistant: Drafting email via Groq for {recipient}...[/bold yellow]")
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
            
            console.print("\n[bold cyan]--- Generated Email Preview ---[/bold cyan]")
            console.print(Markdown(generated_email))
            console.print("[bold cyan]-------------------------------[/bold cyan]")
            
            confirm = input("   ↳ Do you want to send this email? (y/n): ").strip().lower()
            if confirm == 'y':
                result = email_agent.send_email(recipient, subject, generated_email)
                console.print(f"[bold green]AI Assistant: {result}[/bold green]")
            else:
                console.print("[bold yellow]AI Assistant: Email sending cancelled.[/bold yellow]")

        # 4. Check Unread Emails
        elif any(word in user_input for word in ["email", "mail", "emails", "unread"]) or user_input == "1":
            console.print("\n[bold yellow]AI Assistant: Checking unread emails from Gmail...[/bold yellow]")
            try:
                unread_emails = email_agent.fetch_unread_emails(max_results=3)
                if unread_emails:
                    email_result = email_agent.process_emails_with_groq(unread_emails)
                    console.print(Markdown(email_result))
                else:
                    console.print("[bold green]No unread emails found in your inbox.[/bold green]")
            except Exception as e:
                console.print(f"[bold red]Gmail Error: {e}[/bold red]")

        # 5. WhatsApp Assistant
        elif "whatsapp" in user_input or user_input == "3":
            sample_query = input("   ↳ Enter client message: ").strip()
            if not sample_query:
                sample_query = "Hi, I want to know the status of my project delivery and pricing details."
            
            console.print("[bold yellow]AI Assistant: Formulating WhatsApp response...[/bold yellow]")
            try:
                wa_response = whatsapp_agent.process_client_message(sample_query)
                console.print(Markdown(wa_response))
            except Exception as f:
                console.print(f"[bold red]WhatsApp Agent Error: {f}[/bold red]")

        # 6. Calendar Events
        elif "calendar" in user_input or "event" in user_input or user_input == "4":
            console.print("\n[bold yellow]AI Assistant: Fetching upcoming calendar events...[/bold yellow]")
            try:
                upcoming_events = calendar_agent.list_upcoming_events(max_results=5)
                events_text = "\n".join([f"- **{e['summary']}** at {e['start']}" for e in upcoming_events]) if upcoming_events else "No upcoming events found."
                console.print(Markdown(events_text))
            except Exception as e:
                console.print(f"[bold red]Calendar Error: {e}[/bold red]")

        # 7. Generate Meeting Minutes (MoM) from text notes
        elif "mom" in user_input or "meeting" in user_input or user_input == "5":
            notes_input = input("   ↳ Enter meeting notes (or press Enter to use sample notes): ").strip()
            if not notes_input:
                notes_input = "Meeting with client Rahul. Discussed Next.js dashboard layout issues and API fetching errors. Rahul wants the admin panel ready by Friday. Priya will handle Tailwind CSS styling updates."
            
            console.print("[bold yellow]AI Assistant: Generating Meeting Minutes...[/bold yellow]")
            try:
                mom_result = mom_agent.generate_mom(notes_input)
                last_generated_mom = mom_result
                console.print(Markdown(mom_result))
            except Exception as e:
                console.print(f"[bold red]MoM Agent Error: {e}[/bold red]")

        else:
            console.print(f"[bold red]AI Assistant: I didn't catch that ('{user_input}'). Type keywords or type 'mic' to use voice.[/bold red]")
        
        console.print("\n" + "─"*50 + "\n")

if __name__ == "__main__":
    main()