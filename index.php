<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Spotify Listener</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div id="app">
        <div id="background" class="background-blur"></div>
        <div class="content-wrapper">
            <div id="player" class="player-container hidden">
                <div class="album-art-container">
                    <img id="album-art" src="" alt="Album Art">
                    <div id="playing-indicator" class="bars">
                        <div class="bar"></div>
                        <div class="bar"></div>
                        <div class="bar"></div>
                    </div>
                </div>
                <div class="player-controls-wrapper">
                    <div class="track-info">
                        <div class="track-title-wrapper">
                            <a id="track-link" href="#" target="_blank" rel="noopener noreferrer" class="track-link">
                                <h1 id="track-name">Loading...</h1>
                            </a>
                            <svg id="liked-icon" class="hide-element liked-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                                <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                            </svg>
                        </div>
                        <h2 id="artist-name"></h2>
                        <p id="album-name"></p>
                    </div>
                    <div id="progress-wrapper" class="progress-wrapper">
                        <div class="progress-details">
                            <span id="progress-time">0:00</span>
                            <span id="duration-time">0:00</span>
                        </div>
                        <div class="progress-bar">
                            <div id="progress-fill" class="progress-fill"></div>
                        </div>
                    </div>
                    <div id="controls" class="controls">
                        <button id="like-button" class="control-btn like-btn" title="Like this song">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
                                <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                            </svg>
                        </button>
                        <button id="skip-button" class="control-btn" title="Skip this song">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                                <path d="M5.5 4v16L15 12 5.5 4zm13 0v16h2V4h-2z"/>
                            </svg>
                        </button>
                    </div>
                    <div id="queue-container" class="hide-element" style="margin-top: 1rem; width: 100%; position: relative;">
                        <form id="queue-form" style="display: flex; gap: 8px;">
                            <input type="text" id="queue-input" placeholder="Paste link or search song..." class="premium-input" autocomplete="off" required>
                            <button type="submit" id="queue-submit" class="premium-button">Play</button>
                        </form>
                        <ul id="search-results" class="search-results hide-element"></ul>
                    </div>
                    <div id="last-played-wrapper" class="hide-element last-played-banner">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" style="margin-right: 6px;">
                            <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm4.2 14.2L11 11.2V6h1.5v4.2l4.2 4.2-.5 1.8z"/>
                        </svg>
                        <span>Last played <strong id="last-played-time"></strong></span>
                    </div>
                </div>
            </div>
            
            <div id="offline" class="offline-container hidden">
                <svg class="spotify-icon" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.24 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.84.24 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.6.18-1.2.72-1.38 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
                </svg>
                <p>Not listening to anything right now.</p>
            </div>

            <div id="loading" class="offline-container">
                <div class="spinner"></div>
                <p style="margin-top:1rem;color:var(--text-secondary);">Connecting to Spotify...</p>
            </div>
        </div>
    </div>
    <script src="script.js"></script>
</body>
</html>
