const UIElements = {
    app: document.getElementById('app'),
    background: document.getElementById('background'),
    player: document.getElementById('player'),
    offline: document.getElementById('offline'),
    loading: document.getElementById('loading'),
    albumArt: document.getElementById('album-art'),
    trackName: document.getElementById('track-name'),
    trackLink: document.getElementById('track-link'),
    likedIcon: document.getElementById('liked-icon'),
    artistName: document.getElementById('artist-name'),
    albumName: document.getElementById('album-name'),
    progressTime: document.getElementById('progress-time'),
    durationTime: document.getElementById('duration-time'),
    progressFill: document.getElementById('progress-fill'),
    skipButton: document.getElementById('skip-button'),
    likeButton: document.getElementById('like-button'),
    progressWrapper: document.getElementById('progress-wrapper'),
    controls: document.getElementById('controls'),
    lastPlayedWrapper: document.getElementById('last-played-wrapper'),
    lastPlayedTime: document.getElementById('last-played-time'),
    queueContainer: document.getElementById('queue-container'),
    queueForm: document.getElementById('queue-form'),
    queueInput: document.getElementById('queue-input'),
    queueSubmit: document.getElementById('queue-submit'),
    searchResults: document.getElementById('search-results')
};

let currentState = {
    id: null,
    isPlaying: false,
    progressMs: 0,
    durationMs: 0,
    lastUpdate: 0
};

let progressInterval = null;

function formatTime(ms) {
    const totalSeconds = Math.floor(ms / 1000);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

function updateProgress() {
    if (!currentState.isPlaying) return;

    const now = Date.now();
    const diff = now - currentState.lastUpdate;
    const currentMs = Math.min(currentState.progressMs + diff, currentState.durationMs);
    
    UIElements.progressTime.textContent = formatTime(currentMs);
    const percent = (currentMs / currentState.durationMs) * 100;
    UIElements.progressFill.style.width = `${percent}%`;

    if (currentMs >= currentState.durationMs) {
        // Track ended, fetch next track immediately
        fetchCurrentlyPlaying();
    }
}

function renderState(data) {
    if (data && data.config && data.config.queue_and_skip) {
        if (UIElements.queueContainer) UIElements.queueContainer.classList.remove('hide-element');
    } else {
        if (UIElements.queueContainer) UIElements.queueContainer.classList.add('hide-element');
    }

    // Hide loading if it's there
    if (!UIElements.loading.classList.contains('hidden')) {
        UIElements.loading.classList.add('hidden');
    }

    if (!data || (!data.is_playing && !data.recent_item) || (!data.item && !data.recent_item)) {
        // Show offline state purely
        if (!UIElements.player.classList.contains('hidden')) {
            UIElements.player.classList.add('hidden');
            UIElements.player.classList.remove('is-playing');
        }
        
        setTimeout(() => {
            if (UIElements.offline.classList.contains('hidden')) {
                UIElements.offline.classList.remove('hidden');
                UIElements.background.style.backgroundImage = 'none';
            }
        }, 300); // Wait for transition
        
        currentState.isPlaying = false;
        clearInterval(progressInterval);
        progressInterval = null;
        return;
    }

    const item = data.is_playing ? data.item : data.recent_item;
    
    // Switch visibility if coming from offline or loading
    if (!UIElements.offline.classList.contains('hidden')) {
        UIElements.offline.classList.add('hidden');
    }
    
    setTimeout(() => {
        if (UIElements.player.classList.contains('hidden')) {
            UIElements.player.classList.remove('hidden');
        }
    }, UIElements.offline.classList.contains('hidden') ? 0 : 300);

    // Update track metadata if it changed
    if (currentState.id !== item.id) {
        currentState.id = item.id;
        currentTrackId = item.id;
        
        const imageUrl = item.album.images[0]?.url || '';
        UIElements.albumArt.src = imageUrl;
        UIElements.background.style.backgroundImage = `url(${imageUrl})`;
        
        UIElements.trackName.textContent = item.name;
        if (item.external_urls && item.external_urls.spotify) {
            UIElements.trackLink.href = item.external_urls.spotify;
        } else {
            UIElements.trackLink.removeAttribute('href');
        }
        
        UIElements.artistName.textContent = item.artists.map(a => a.name).join(', ');
        UIElements.albumName.textContent = item.album.name;
        UIElements.durationTime.textContent = formatTime(item.duration_ms);
        
        document.title = `${item.name} - ${item.artists[0]?.name}`;

        // Reset like button for new track
        if (UIElements.likeButton) {
            UIElements.likeButton.classList.remove('is-liked');
        }
    }

    if (data.is_liked && UIElements.likedIcon) {
        UIElements.likedIcon.classList.remove('hide-element');
    } else if (UIElements.likedIcon) {
        UIElements.likedIcon.classList.add('hide-element');
    }

    if (UIElements.likeButton) {
        if (data.is_liked) {
            UIElements.likeButton.classList.add('is-liked');
            document.body.classList.remove('not-liked');
        } else {
            UIElements.likeButton.classList.remove('is-liked');
            document.body.classList.add('not-liked');
        }
    }

    // Always update progress state
    currentState.isPlaying = data.is_playing;

    if (currentState.isPlaying) {
        UIElements.player.classList.add('is-playing');
        UIElements.progressWrapper.classList.remove('hide-element');
        UIElements.controls.classList.remove('hide-element');
        UIElements.lastPlayedWrapper.classList.add('hide-element');
        
        currentState.progressMs = data.progress_ms;
        currentState.durationMs = item.duration_ms;
        currentState.lastUpdate = Date.now();
        if (!progressInterval) {
            progressInterval = setInterval(updateProgress, 100);
        }
    } else {
        UIElements.player.classList.remove('is-playing');
        UIElements.progressWrapper.classList.add('hide-element');
        UIElements.controls.classList.add('hide-element');
        UIElements.lastPlayedWrapper.classList.remove('hide-element');
        
        if (data.played_at) {
            const timeDiff = Date.now() - new Date(data.played_at).getTime();
            const mins = Math.floor(timeDiff / 60000);
            if (mins < 1) {
                UIElements.lastPlayedTime.textContent = 'just now';
            } else if (mins < 60) {
                UIElements.lastPlayedTime.textContent = `${mins} min${mins !== 1 ? 's' : ''} ago`;
            } else {
                const hours = Math.floor(mins / 60);
                if (hours < 24) {
                    UIElements.lastPlayedTime.textContent = `${hours} hr${hours !== 1 ? 's' : ''} ago`;
                } else {
                    const days = Math.floor(hours / 24);
                    UIElements.lastPlayedTime.textContent = `${days} day${days !== 1 ? 's' : ''} ago`;
                }
            }
        }
        
        clearInterval(progressInterval);
        progressInterval = null;
        updateProgress(); // freeze
    }
}

async function fetchCurrentlyPlaying() {
    try {
        const response = await fetch('api.php');
        if (!response.ok) {
            renderState({ is_playing: false });
            return;
        }
        
        const data = await response.text();
        if (!data) {
            renderState({ is_playing: false });
            return;
        }

        const json = JSON.parse(data);
        renderState(json);
    } catch (e) {
        console.error('Fetch error:', e);
        renderState({ is_playing: false });
    }
}

let currentTrackId = null;

// Initial fetch
fetchCurrentlyPlaying();

async function skipSong() {
    if (!currentState.isPlaying || UIElements.skipButton.classList.contains('is-loading')) return;
    
    UIElements.skipButton.classList.add('is-loading');
    try {
        const response = await fetch('api.php?action=next', { method: 'POST' });
        if (response.ok) {
            // Eagerly fetch next track
            setTimeout(fetchCurrentlyPlaying, 500);
        }
    } catch (e) {
        console.error('Skip error:', e);
    } finally {
        setTimeout(() => {
            UIElements.skipButton.classList.remove('is-loading');
        }, 800);
    }
}

if (UIElements.skipButton) {
    UIElements.skipButton.addEventListener('click', skipSong);
}

async function likeSong() {
    if (!currentTrackId || UIElements.likeButton.classList.contains('is-loading')) return;
    if (UIElements.likeButton.classList.contains('is-liked')) return; // already liked

    UIElements.likeButton.classList.add('is-loading');
    try {
        const formData = new FormData();
        formData.append('track_id', currentTrackId);
        const response = await fetch('api.php?action=like', { method: 'POST', body: formData });
        if (response.ok) {
            UIElements.likeButton.classList.add('is-liked');
            if (UIElements.likedIcon) UIElements.likedIcon.classList.remove('hide-element');
        }
    } catch (e) {
        console.error('Like error:', e);
    } finally {
        UIElements.likeButton.classList.remove('is-loading');
    }
}

if (UIElements.likeButton) {
    UIElements.likeButton.addEventListener('click', likeSong);
}

async function queueAndSkipURI(uri) {
    UIElements.queueSubmit.textContent = '...';
    UIElements.queueSubmit.disabled = true;

    try {
        const formData = new FormData();
        formData.append('uri', uri);
        const response = await fetch('api.php?action=queue_and_skip', {
            method: 'POST',
            body: formData
        });
        if (response.ok) {
            UIElements.queueInput.value = '';
            setTimeout(fetchCurrentlyPlaying, 1000);
        } else {
            alert('Failed to play the requested song. Make sure you pasted a valid Spotify track link.');
        }
    } catch (e) {
        console.error('Queue error', e);
    } finally {
        UIElements.queueSubmit.textContent = 'Play';
        UIElements.queueSubmit.disabled = false;
    }
}

if (UIElements.queueForm) {
    let searchTimeout = null;

    UIElements.queueInput.addEventListener('input', (e) => {
        const query = e.target.value.trim();
        
        if (searchTimeout) clearTimeout(searchTimeout);
        
        if (!query || query.includes('open.spotify.com/')) {
            UIElements.searchResults.classList.add('hide-element');
            return;
        }

        searchTimeout = setTimeout(async () => {
            try {
                const response = await fetch(`api.php?action=search&q=${encodeURIComponent(query)}`);
                if (response.ok) {
                    const results = await response.json();
                    renderSearchResults(results);
                }
            } catch (err) {
                console.error('Search error', err);
            }
        }, 400);
    });
    
    document.addEventListener('click', (e) => {
        if (!UIElements.queueContainer.contains(e.target)) {
            UIElements.searchResults.classList.add('hide-element');
        }
    });

    UIElements.queueForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const rawUrl = UIElements.queueInput.value.trim();
        if (!rawUrl) return;

        let uri = rawUrl;
        if (rawUrl.includes('open.spotify.com/track/')) {
            const urlParts = rawUrl.split('track/')[1];
            const trackId = urlParts.split('?')[0];
            uri = `spotify:track:${trackId}`;
        }

        queueAndSkipURI(uri);
    });
}

function renderSearchResults(results) {
    if (!results || results.length === 0) {
        UIElements.searchResults.classList.add('hide-element');
        return;
    }
    
    UIElements.searchResults.innerHTML = '';
    
    results.forEach(track => {
        const li = document.createElement('li');
        li.className = 'search-result-item';
        li.innerHTML = `
            <img src="${track.image || ''}" class="search-result-img" alt="">
            <div class="search-result-text">
                <div class="search-result-title">${track.name}</div>
                <div class="search-result-artist">${track.artist}</div>
            </div>
        `;
        li.addEventListener('click', () => {
            UIElements.queueInput.value = track.name; 
            UIElements.searchResults.classList.add('hide-element');
            queueAndSkipURI(track.uri);
        });
        UIElements.searchResults.appendChild(li);
    });
    
    UIElements.searchResults.classList.remove('hide-element');
}

// Poll every 2 seconds
setInterval(fetchCurrentlyPlaying, 4000);

// --- Double-click gestures ---
function showGestureRipple(x, y, type) {
    const ripple = document.createElement('div');
    ripple.className = `gesture-ripple gesture-ripple--${type}`;

    const icon = type === 'like'
        ? `<svg viewBox="0 0 24 24" width="36" height="36" fill="currentColor"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>`
        : `<svg viewBox="0 0 24 24" width="36" height="36" fill="currentColor"><path d="M5.5 4v16L15 12 5.5 4zm13 0v16h2V4h-2z"/></svg>`;

    ripple.innerHTML = icon;
    ripple.style.left = `${x}px`;
    ripple.style.top = `${y}px`;
    document.body.appendChild(ripple);

    ripple.addEventListener('animationend', () => ripple.remove());
}

document.addEventListener('dblclick', (e) => {
    console.log("double click")
    // Only block actual interactive controls, not the whole page
    if (e.target.closest('button, input, textarea, select')) return;

    const isLeftSide = e.clientX < window.innerWidth / 2;

    if (isLeftSide) {
        showGestureRipple(e.clientX, e.clientY, 'like');
        likeSong();
    } else {
        showGestureRipple(e.clientX, e.clientY, 'skip');
        skipSong();
    }
});
