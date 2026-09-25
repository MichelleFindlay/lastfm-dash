(function () {
    "use strict";

    var config = window.APP_CONFIG || { pollIntervalMs: 15000 };
    var lastImage = null;
    var activeLayer = "a";

    var els = {
        trackArtImg: document.querySelector("[data-track-art-img]"),
        albumArtImg: document.querySelector("[data-album-art-img]"),
        trackArtFallback: document.querySelector("[data-track-art-fallback]"),
        albumArtFallback: document.querySelector("[data-album-art-fallback]"),
        name: document.querySelector("[data-track-name]"),
        artist: document.querySelector("[data-track-artist]"),
        album: document.querySelector("[data-track-album]"),
        badge: document.querySelector("[data-status-badge]"),
        updated: document.querySelector("[data-updated]"),
        bgA: document.querySelector("[data-bg-a]"),
        bgB: document.querySelector("[data-bg-b]"),
    };

    function setText(el, value) {
        if (el) {
            el.textContent = value || "";
        }
    }

    function setArt(imgEl, fallbackEl, url) {
        if (!imgEl) {
            return;
        }
        if (url) {
            imgEl.src = url;
            imgEl.style.display = "";
            if (fallbackEl) {
                fallbackEl.style.display = "none";
            }
        } else {
            imgEl.style.display = "none";
            if (fallbackEl) {
                fallbackEl.style.display = "";
            }
        }
    }

    /**
     * Crossfade the blurred background to a new album art image.
     */
    function updateBackground(imageUrl) {
        if (!imageUrl || imageUrl === lastImage) {
            return;
        }
        lastImage = imageUrl;

        var incoming = activeLayer === "a" ? els.bgB : els.bgA;
        var outgoing = activeLayer === "a" ? els.bgA : els.bgB;

        incoming.style.backgroundImage = "url('" + imageUrl + "')";
        incoming.classList.add("visible");
        outgoing.classList.remove("visible");

        activeLayer = activeLayer === "a" ? "b" : "a";

        extractPalette(imageUrl);
    }

    /**
     * Sample the album art on a canvas to derive an accent colour for the
     * theme. Falls back to the default accent if the image can't be read
     * (e.g. blocked by CORS), since the blurred background still updates
     * regardless.
     */
    function extractPalette(imageUrl) {
        var img = new Image();
        img.crossOrigin = "anonymous";

        img.onload = function () {
            try {
                var size = 24;
                var canvas = document.createElement("canvas");
                canvas.width = size;
                canvas.height = size;
                var ctx = canvas.getContext("2d");
                ctx.drawImage(img, 0, 0, size, size);
                var data = ctx.getImageData(0, 0, size, size).data;

                var r = 0, g = 0, b = 0, weight = 0;

                for (var i = 0; i < data.length; i += 4) {
                    var pr = data[i], pg = data[i + 1], pb = data[i + 2];
                    var max = Math.max(pr, pg, pb);
                    var min = Math.min(pr, pg, pb);
                    var sat = max === 0 ? 0 : (max - min) / max;
                    // Weight saturated, mid-brightness pixels more heavily so the
                    // accent isn't dragged towards a muddy grey average.
                    var w = 0.15 + sat;

                    r += pr * w;
                    g += pg * w;
                    b += pb * w;
                    weight += w;
                }

                r = Math.round(r / weight);
                g = Math.round(g / weight);
                b = Math.round(b / weight);

                applyAccent(r, g, b);
            } catch (err) {
                // Canvas was tainted (no CORS headers) — keep the default accent.
            }
        };

        img.onerror = function () {};
        img.src = imageUrl;
    }

    function applyAccent(r, g, b) {
        var root = document.documentElement.style;
        root.setProperty("--accent", "rgb(" + r + ", " + g + ", " + b + ")");
        root.setProperty("--accent-soft", "rgba(" + r + ", " + g + ", " + b + ", 0.35)");
    }

    function renderTrack(track) {
        if (!track || !track.name) {
            return;
        }

        setText(els.name, track.name);
        setText(els.artist, track.artist);
        setText(els.album, track.album);

        var initial = (track.name || "?").charAt(0).toUpperCase();
        if (els.trackArtFallback) els.trackArtFallback.textContent = initial;
        if (els.albumArtFallback) els.albumArtFallback.textContent = initial;

        setArt(els.trackArtImg, els.trackArtFallback, track.track_art || track.image);
        setArt(els.albumArtImg, els.albumArtFallback, track.album_art || track.track_art || track.image);

        if (els.badge) {
            if (track.now_playing) {
                els.badge.classList.add("live");
                els.badge.innerHTML = '<span class="eq"><span></span><span></span><span></span></span> Now scrobbling';
            } else {
                els.badge.classList.remove("live");
                els.badge.textContent = "Last played";
            }
        }

        if (track.image) {
            updateBackground(track.image);
        }
    }

    function poll() {
        fetch("api.php", { cache: "no-store" })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.ok) {
                    renderTrack(data);
                    if (els.updated) {
                        els.updated.textContent = "Updated " + new Date().toLocaleTimeString();
                    }
                }
            })
            .catch(function () {
                /* silently retry on next interval */
            });
    }

    // Kick off background theming from the server-rendered initial track,
    // then keep polling for now-playing changes.
    if (window.INITIAL_TRACK && window.INITIAL_TRACK.image) {
        updateBackground(window.INITIAL_TRACK.image);
    }

    setInterval(poll, config.pollIntervalMs);
})();
