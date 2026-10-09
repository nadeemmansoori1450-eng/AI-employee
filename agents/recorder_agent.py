import os
import threading
import numpy as np
import scipy.io.wavfile as wav
from groq import Groq
from dotenv import load_dotenv

load_dotenv()

# Safe import for cloud servers (Render/Vercel) where sounddevice/PortAudio might be missing
try:
    import sounddevice as sd
except (ImportError, OSError):
    sd = None

class RecorderAgent:
    def __init__(self):
        self.client = Groq(api_key=os.getenv("GROQ_API_KEY"))

    def record_and_transcribe(self, output_filename="temp_meeting.wav"):
        """Records audio from microphone until the user presses Enter, then transcribes via Groq Whisper."""
        if sd is None:
            raise RuntimeError("Sounddevice (PortAudio) is not available on this cloud environment. Live recording should be handled on the client-side browser.")

        fs = 44100  # Sample rate
        channels = 1
        audio_data = []

        # Callback function to collect audio chunks
        def callback(indata, frames, time, status):
            audio_data.append(indata.copy())

        print(f"\n🎙️ Recording live meeting audio... [ Press ENTER to stop recording ]")
        
        # Start recording stream in background
        stream = sd.InputStream(samplerate=fs, channels=channels, callback=callback, dtype='int16')
        
        with stream:
            # Wait for user to press Enter to stop
            input()
        
        print("🛑 Recording stopped. Processing audio...")

        if not audio_data:
            return "No audio recorded."

        # Combine all recorded chunks into a single numpy array
        recorded_audio = np.concatenate(audio_data, axis=0)
        
        # Save as WAV file
        wav.write(output_filename, fs, recorded_audio)
        print("✅ Audio saved. Transcribing via Groq Whisper...")

        # Transcribe using Groq Whisper API
        with open(output_filename, "rb") as file:
            transcription = self.client.audio.transcriptions.create(
                file=(output_filename, file.read()),
                model="whisper-large-v3",
                response_format="text"
            )
        
        # Cleanup temporary file
        if os.path.exists(output_filename):
            os.remove(output_filename)

        return transcription