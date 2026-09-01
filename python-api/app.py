from flask import Flask, request, jsonify
from flask_cors import CORS
import yt_dlp
import traceback

app = Flask(__name__)
# Allow CORS so Angular frontend can call this API
CORS(app, resources={r"/*": {"origins": "*"}})

@app.route('/')
def home():
    return "GanaTube Python Extraction API is running!"

@app.route('/api/extract', methods=['GET'])
def extract_audio():
    video_id = request.args.get('videoId')
    if not video_id:
        return jsonify({"error": "No videoId provided"}), 400

    url = f"https://www.youtube.com/watch?v={video_id}"
    
    # yt-dlp options to extract the best audio format without downloading the file to server
    ydl_opts = {
        'format': 'bestaudio/best',
        'quiet': True,
        'no_warnings': True,
        'skip_download': True, # DO NOT download to server (ZERO LOAD)
        'extract_flat': False,
    }
    
    try:
        with yt_dlp.YoutubeDL(ydl_opts) as ydl:
            info = ydl.extract_info(url, download=False)
            
            if 'url' in info:
                stream_url = info['url']
                # The direct audio stream from Google servers!
                return jsonify({
                    "videoId": video_id,
                    "title": info.get('title'),
                    "streamUrl": stream_url,
                    "ext": info.get('ext', 'm4a'),
                    "message": "Stream fetched directly, ZERO server load!"
                })
            else:
                return jsonify({"error": "Could not extract stream URL"}), 500

    except Exception as e:
        print(f"Error extracting {video_id}: {traceback.format_exc()}")
        return jsonify({"error": str(e)}), 500


if __name__ == '__main__':
    # Run the server on port 5000
    app.run(host='0.0.0.0', port=5000)
