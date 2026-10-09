import os
from groq import Groq
from dotenv import load_dotenv

load_dotenv()

class MoMAgent:
    def __init__(self):
        self.client = Groq(api_key=os.getenv("GROQ_API_KEY"))

    def generate_mom(self, meeting_notes):
        """Processes transcript and generates clean, direct meeting notes exactly as spoken."""
        prompt = f"""
        You are a precise meeting note-taker. Read the following meeting transcript and summarize it into clean, direct notes. 
        Do NOT invent corporate templates, attendees, or fictional milestones if they weren't mentioned. 
        Keep it simple, exact, and strictly based on what was actually said in the transcript. Use clear bullet points.

        Transcript:
        "{meeting_notes}"
        """

        response = self.client.chat.completions.create(
            model="openai/gpt-oss-120b",
            messages=[
                {"role": "system", "content": "You are a precise meeting note-taking assistant."},
                {"role": "user", "content": prompt}
            ],
            temperature=0.2
        )
        return response.choices[0].message.content



# import os
# from groq import Groq
# from dotenv import load_dotenv

# load_dotenv()

# class MoMAgent:
#     def __init__(self):
#         self.client = Groq(api_key=os.getenv("GROQ_API_KEY"))

#     def generate_mom(self, meeting_notes):
#         """Processes rough meeting notes and generates professional Minutes of Meeting (MoM)."""
#         prompt = f"""
#         You are an expert Executive Assistant. Analyze the following rough meeting notes or transcript, and generate structured Minutes of Meeting (MoM).
#         Include the following sections:
#         1. **Meeting Overview** (Brief summary)
#         2. **Key Discussion Points** (Bullet points)
#         3. **Action Items** (Who is responsible and deadlines if mentioned)
#         4. **Next Steps**

#         Rough Notes:
#         "{meeting_notes}"
#         """

#         response = self.client.chat.completions.create(
#             model="openai/gpt-oss-120b",
#             messages=[
#                 {"role": "system", "content": "You are a professional meeting summarization assistant."},
#                 {"role": "user", "content": prompt}
#             ],
#             temperature=0.2
#         )
#         return response.choices[0].message.content