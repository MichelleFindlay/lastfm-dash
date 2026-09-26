(function () {
    "use strict";

    var config = window.APP_CONFIG || { pollIntervalMs: 15000 };
    var lastImage = null;
    var activeLayer = "a";
    var lastCheckTime = Date.now();

    var els = {
        artImg: document.querySelector("[data-art-img]"),
        artFallback: document.querySelector("[data-art-fallback]"),
        name: document.querySelector("[data-track-name]"),
        artist: document.querySelector("[data-track-artist]"),
        album: document.querySelector("[data-track-album]"),
        badge: document.querySelector("[data-status-badge]"),
        updated: document.querySelector("[data-updated]"),
        bgA: document.querySelector("[data-bg-a]"),
        bgB: document.querySelector("[data-bg-b]"),
        listenSpotify: document.querySelector("[data-listen-spotify]"),
        listenYoutube: document.querySelector("[data-listen-youtube]"),
        prevWrap: document.querySelector("[data-prev-track]"),
        prevArtImg: document.querySelector("[data-prev-art-img]"),
        prevArtFallback: document.querySelector("[data-prev-art-fallback]"),
        prevName: document.querySelector("[data-prev-track-name]"),
        prevArtist: document.querySelector("[data-prev-track-artist]"),
    };

    function setText(el, value) {
        if (el) {
            el.textContent = value || "";
        }
    }

    // Last.fm's API sometimes lists an image URL that 404s on its own CDN
    // (seen in practice: it advertises a large size that's missing while
    // smaller sizes of the same image work fine). Rather than add a slow
    // server-side HEAD request per image to verify, fall back to the
    // existing letter-placeholder UI whenever a load actually fails.
    function fallBackToLetterOnError(imgEl, fallbackEl) {
        if (!imgEl || !fallbackEl) {
            return;
        }
        imgEl.addEventListener("error", function () {
            imgEl.style.display = "none";
            fallbackEl.style.display = "";
        });
    }

    fallBackToLetterOnError(els.artImg, els.artFallback);
    fallBackToLetterOnError(els.prevArtImg, els.prevArtFallback);

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

    // Once extractPalette() sets --accent via JS, it overrides the CSS
    // default indefinitely — there was previously no path back, so a track
    // with no art at all would keep showing whatever the last track with
    // art left behind. Reset everything to its CSS default when that
    // happens: fading out the background layer reveals the plain dark page
    // background, and removing the --accent overrides (rather than
    // hardcoding the default here too) lets them fall back to :root's
    // declared values, still through the same 5s --color-transition every
    // other accent change already uses.
    function resetTheme() {
        if (lastImage === null) {
            return; // already at the default; nothing to reset
        }
        lastImage = null;

        if (els.bgA) els.bgA.classList.remove("visible");
        if (els.bgB) els.bgB.classList.remove("visible");

        var root = document.documentElement.style;
        root.removeProperty("--accent");
        root.removeProperty("--accent-soft");
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

                // Keep the accent readable no matter what the album art
                // looks like: it's used both as white-text-on-accent
                // (active period buttons, art fallback tiles) and as
                // accent-text-on-the-page's-own-dark-background (status
                // badge, footer links). Both need the accent's own
                // brightness to sit in a safe middle band — and since
                // perceived brightness depends on hue (yellow reads much
                // brighter than blue at the same HSL lightness), the only
                // reliable way to do that is to target real WCAG relative
                // luminance directly, not raw HSL lightness. Hue and
                // saturation are left untouched, so the theme still
                // visibly follows the art's actual colour.
                var hsl = rgbToHsl(r, g, b);
                var safeRgb = ensureSafeLuminance(hsl[0], hsl[1], hsl[2]);

                applyAccent(safeRgb[0], safeRgb[1], safeRgb[2]);
            } catch (err) {
                // Canvas was tainted (no CORS headers) — keep the default accent.
            }
        };

        img.onerror = function () {};
        img.src = imageUrl;
    }

    function rgbToHsl(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b);
        var h = 0, s = 0, l = (max + min) / 2;

        if (max !== min) {
            var d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            switch (max) {
                case r: h = (g - b) / d + (g < b ? 6 : 0); break;
                case g: h = (b - r) / d + 2; break;
                default: h = (r - g) / d + 4;
            }
            h /= 6;
        }

        return [h * 360, s * 100, l * 100];
    }

    function hslToRgb(h, s, l) {
        h /= 360; s /= 100; l /= 100;
        var r, g, b;

        if (s === 0) {
            r = g = b = l;
        } else {
            var hue2rgb = function (p, q, t) {
                if (t < 0) t += 1;
                if (t > 1) t -= 1;
                if (t < 1 / 6) return p + (q - p) * 6 * t;
                if (t < 1 / 2) return q;
                if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
                return p;
            };
            var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
            var p = 2 * l - q;
            r = hue2rgb(p, q, h + 1 / 3);
            g = hue2rgb(p, q, h);
            b = hue2rgb(p, q, h - 1 / 3);
        }

        return [Math.round(r * 255), Math.round(g * 255), Math.round(b * 255)];
    }

    function relativeLuminance(r, g, b) {
        function linearize(c) {
            c /= 255;
            return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * linearize(r) + 0.7152 * linearize(g) + 0.0722 * linearize(b);
    }

    // Binary-searches lightness (at a fixed hue/saturation) until the
    // resulting colour's WCAG relative luminance lands in a band that
    // reads clearly both as white-text-on-accent and as accent-text on the
    // page's near-black background — see the call site for why luminance
    // rather than raw HSL lightness is the right thing to target.
    function ensureSafeLuminance(h, s, l) {
        var MIN_LUM = 0.12;
        var MAX_LUM = 0.16;

        var rgb = hslToRgb(h, s, l);
        var lum = relativeLuminance(rgb[0], rgb[1], rgb[2]);
        if (lum >= MIN_LUM && lum <= MAX_LUM) {
            return rgb;
        }

        var lo = 0, hi = 100, result = rgb;
        for (var i = 0; i < 18; i++) {
            var mid = (lo + hi) / 2;
            var testRgb = hslToRgb(h, s, mid);
            var testLum = relativeLuminance(testRgb[0], testRgb[1], testRgb[2]);
            result = testRgb;

            if (testLum < MIN_LUM) {
                lo = mid;
            } else if (testLum > MAX_LUM) {
                hi = mid;
            } else {
                break;
            }
        }

        return result;
    }

    function applyAccent(r, g, b) {
        var root = document.documentElement.style;
        root.setProperty("--accent", "rgb(" + r + ", " + g + ", " + b + ")");
        root.setProperty("--accent-soft", "rgba(" + r + ", " + g + ", " + b + ", 0.35)");
    }

    function setListenLink(linkEl, linkData, serviceName) {
        if (!linkEl) {
            return;
        }
        if (!linkData || !linkData.url) {
            linkEl.style.display = "none";
            return;
        }
        linkEl.href = linkData.url;
        linkEl.title = (linkData.verified ? "Listen on " : "Search on ") + serviceName;
        var label = linkEl.querySelector(".listen-label");
        if (label) {
            label.textContent = linkData.verified ? "Listen" : "Search";
        }
        linkEl.style.display = "";
    }

    function renderTrack(track) {
        if (!track || !track.name) {
            return;
        }

        setText(els.name, track.name);
        setText(els.artist, track.artist);
        setText(els.album, track.album);

        var initial = (track.name || "?").charAt(0).toUpperCase();
        if (els.artFallback) els.artFallback.textContent = initial;

        setArt(els.artImg, els.artFallback, track.image);

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
        } else {
            resetTheme();
        }

        if (track.listen) {
            setListenLink(els.listenSpotify, track.listen.spotify, "Spotify");
            setListenLink(els.listenYoutube, track.listen.youtube, "YouTube Music");
        }
    }

    function renderPreviousTrack(prev) {
        if (!els.prevWrap) {
            return;
        }
        if (!prev || !prev.name) {
            els.prevWrap.style.display = "none";
            return;
        }

        els.prevWrap.style.display = "";
        setText(els.prevName, prev.name);
        setText(els.prevArtist, prev.artist);

        var initial = (prev.name || "?").charAt(0).toUpperCase();
        if (els.prevArtFallback) els.prevArtFallback.textContent = initial;

        setArt(els.prevArtImg, els.prevArtFallback, prev.image);
    }

    function renderStats(stats) {
        if (!stats) {
            return;
        }
        Object.keys(stats).forEach(function (key) {
            var node = document.querySelector('[data-stat="' + key + '"]');
            if (node) {
                node.textContent = stats[key];
            }
        });
    }

    function poll() {
        fetch("api.php", { cache: "no-store" })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.ok) {
                    renderTrack(data);
                    renderPreviousTrack(data.previous);
                    renderStats(data.stats);
                    lastCheckTime = Date.now();
                    updateElapsedLabel();
                }
            })
            .catch(function () {
                /* silently retry on next interval */
            });
    }

    function updateElapsedLabel() {
        if (!els.updated) {
            return;
        }
        var secs = Math.max(0, Math.round((Date.now() - lastCheckTime) / 1000));
        els.updated.textContent = secs < 1 ? "Updated just now" : "Updated " + secs + "s ago";
    }

    // Kick off background theming from the server-rendered initial track,
    // then keep polling for now-playing changes.
    if (window.INITIAL_TRACK && window.INITIAL_TRACK.image) {
        updateBackground(window.INITIAL_TRACK.image);
    }

    updateElapsedLabel();
    setInterval(updateElapsedLabel, 1000);
    setInterval(poll, config.pollIntervalMs);

    // --- Insight widget popups ---

    var modalOverlay = document.querySelector("[data-modal-overlay]");
    var modalBody = document.querySelector("[data-modal-body]");
    var modalClose = document.querySelector("[data-modal-close]");
    var widgetCache = {};

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function openModal() {
        if (!modalOverlay) return;
        modalOverlay.hidden = false;
    }

    function closeModal() {
        if (!modalOverlay) return;
        modalOverlay.hidden = true;
    }

    function loadWidget(id) {
        if (!modalBody) return;
        openModal();
        modalBody.innerHTML = "";
        modalBody.appendChild(el("div", "widget-loading", "Loading…"));

        if (widgetCache[id]) {
            renderWidget(id, widgetCache[id]);
            return;
        }

        fetch("widgets.php?id=" + encodeURIComponent(id), { cache: "no-store" })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                if (!payload || !payload.ok) {
                    modalBody.innerHTML = "";
                    modalBody.appendChild(el("div", "widget-empty", "Couldn't load this widget."));
                    return;
                }
                widgetCache[id] = payload.data;
                renderWidget(id, payload.data);
            })
            .catch(function () {
                modalBody.innerHTML = "";
                modalBody.appendChild(el("div", "widget-empty", "Couldn't load this widget."));
            });
    }

    var WIDGET_TITLES = {
        listening_clock: "Listening Clock",
        energy_curve: "Energy Curve",
        distance: "Distance Listened",
        festival: "If Your Year Were a Festival",
        mood: "Mood Weather",
        bpm: "BPM Average",
        before_famous: "Before They Were Famous",
        obscurity: "Obscurity Index",
    };

    var WIDGET_RENDERERS = {
        listening_clock: renderListeningClock,
        energy_curve: renderEnergyCurve,
        distance: renderDistance,
        festival: renderFestival,
        mood: renderMood,
        bpm: renderBpm,
        before_famous: renderBeforeFamous,
        obscurity: renderObscurity,
    };

    function renderWidget(id, data) {
        modalBody.innerHTML = "";
        modalBody.appendChild(el("h3", "widget-title", WIDGET_TITLES[id] || ""));

        if (!data || data.available === false) {
            modalBody.appendChild(el("div", "widget-empty", "Not enough listening history yet — check back after a few more scrobbles."));
            return;
        }

        var renderer = WIDGET_RENDERERS[id];
        if (renderer) {
            renderer(data);
        }
    }

    function renderListeningClock(data) {
        var max = Math.max.apply(null, data.hours);
        // Labels sit at rMax + 18 from center, so the viewBox needs enough
        // margin beyond that for the "0:00"-style text to not get clipped
        // at the edge — it was previously sized with almost none.
        var cx = 130, cy = 130, rMax = 90, rMin = 22;
        var svgNS = "http://www.w3.org/2000/svg";
        var svg = document.createElementNS(svgNS, "svg");
        svg.setAttribute("viewBox", "0 0 260 260");
        svg.setAttribute("class", "clock-svg");

        for (var h = 0; h < 24; h++) {
            var angle = (h / 24) * Math.PI * 2 - Math.PI / 2;
            var frac = max > 0 ? data.hours[h] / max : 0;
            var r = rMin + frac * (rMax - rMin);
            var line = document.createElementNS(svgNS, "line");
            line.setAttribute("x1", cx + Math.cos(angle) * rMin);
            line.setAttribute("y1", cy + Math.sin(angle) * rMin);
            line.setAttribute("x2", cx + Math.cos(angle) * r);
            line.setAttribute("y2", cy + Math.sin(angle) * r);
            line.setAttribute("stroke", "var(--accent)");
            line.setAttribute("stroke-width", "6");
            line.setAttribute("stroke-linecap", "round");
            svg.appendChild(line);
        }

        [0, 6, 12, 18].forEach(function (h) {
            var angle = (h / 24) * Math.PI * 2 - Math.PI / 2;
            var text = document.createElementNS(svgNS, "text");
            text.setAttribute("x", cx + Math.cos(angle) * (rMax + 18));
            text.setAttribute("y", cy + Math.sin(angle) * (rMax + 18));
            text.setAttribute("text-anchor", "middle");
            text.setAttribute("dominant-baseline", "middle");
            text.textContent = h + ":00";
            svg.appendChild(text);
        });

        modalBody.appendChild(el("div", "widget-headline", data.label));
        modalBody.appendChild(svg);
        modalBody.appendChild(el("div", "widget-subtext", data.sample_note || ""));
    }

    function renderEnergyCurve(data) {
        var max = Math.max.apply(null, data.days);
        var bars = el("div", "energy-bars");

        data.days.forEach(function (count, i) {
            var col = el("div", "energy-bar-col" + (data.labels[i] === data.peak_day ? " peak" : ""));
            var bar = el("div", "energy-bar");
            var pct = max > 0 ? Math.max(4, Math.round((count / max) * 100)) : 4;
            bar.style.height = pct + "%";
            col.appendChild(bar);
            col.appendChild(el("div", "energy-bar-label", data.labels[i]));
            bars.appendChild(col);
        });

        modalBody.appendChild(el("div", "widget-headline", "Peak day: " + data.peak_day));
        modalBody.appendChild(bars);
        modalBody.appendChild(el("div", "widget-subtext", "Based on scrobble volume through the week — Last.fm doesn't expose real audio tempo/energy data, so this measures how much you listen, not how “energetic” the tracks are."));
    }

    function renderDistance(data) {
        modalBody.appendChild(el("div", "widget-headline", data.total_hours.toLocaleString() + " hours listened"));
        modalBody.appendChild(el("div", "widget-subtext", "That's about " + data.total_days.toLocaleString() + " full days"));

        var list = el("ul", "distance-list");
        data.comparisons.forEach(function (c) {
            var li = document.createElement("li");
            li.className = "distance-row";

            var top = el("div", "distance-row-top");
            var left = document.createElement("span");
            left.appendChild(el("span", "distance-count", "~" + c.count.toLocaleString()));
            left.appendChild(document.createTextNode(" " + c.label));
            top.appendChild(left);
            top.appendChild(el("span", null, c.pct + "%"));
            li.appendChild(top);

            var bar = el("div", "distance-bar");
            var fill = el("div", "distance-bar-fill");
            fill.style.width = c.pct + "%";
            bar.appendChild(fill);
            li.appendChild(bar);

            list.appendChild(li);
        });
        modalBody.appendChild(list);
        modalBody.appendChild(el("div", "widget-subtext", "Estimated assuming ~3.5 minutes per track — Last.fm doesn't record real track durations for most scrobbles."));
    }

    function renderFestival(data) {
        var poster = el("div", "festival-poster");
        poster.appendChild(el("div", "festival-poster-title", data.year + " Listening Festival"));
        poster.appendChild(el("div", "festival-poster-sub", "Presented by your scrobbles"));

        var tiers = { headliner: [], main: [], tent: [] };
        data.lineup.forEach(function (a) {
            tiers[a.tier].push(a.name);
        });

        if (tiers.headliner.length) {
            poster.appendChild(el("div", "festival-tier festival-tier-headliner", tiers.headliner.join("  •  ")));
        }
        if (tiers.main.length) {
            poster.appendChild(el("div", "festival-tier festival-tier-main", tiers.main.join("   ")));
        }
        if (tiers.tent.length) {
            poster.appendChild(el("div", "festival-tier festival-tier-tent", tiers.tent.join("   ")));
        }

        modalBody.appendChild(poster);
    }

    function renderMood(data) {
        modalBody.appendChild(el("div", "widget-headline", data.month + "'s Forecast"));

        data.forecast.forEach(function (m) {
            var row = el("div", "mood-row");
            row.appendChild(el("span", "mood-name", m.mood));
            var track = el("div", "mood-bar-track");
            var fill = el("div", "mood-bar-fill");
            fill.style.width = m.pct + "%";
            track.appendChild(fill);
            row.appendChild(track);
            row.appendChild(el("span", "mood-pct", m.pct + "%"));
            modalBody.appendChild(row);
        });

        var top = data.forecast[0];
        var second = data.forecast[1];
        var summary = top ? top.label : "";
        if (second) {
            summary += ", " + second.label;
        }
        modalBody.appendChild(el("div", "widget-subtext", summary + " — derived from your top artists' community tags."));
    }

    // One heartbeat "blip" (P bump, QRS spike, T bump), baseline at y=45,
    // drawn left-to-right within a 40-wide unit cell so units can repeat
    // edge to edge with no visible seam. ECG_UNIT_WIDTH must stay in sync
    // with the -40px shift in style.css's ecg-scroll keyframes.
    var ECG_UNIT_WIDTH = 40;
    var ECG_UNIT_HEIGHT = 90;
    var ECG_UNIT_POINTS = [
        [0, 45], [10, 45], [13, 40], [16, 45], [18, 45],
        [19, 50], [21, 8], [23, 60], [25, 45], [28, 45],
        [32, 34], [36, 45], [40, 45]
    ];

    function buildEcgPoints(units) {
        var pts = [];
        for (var u = 0; u < units; u++) {
            var offset = u * ECG_UNIT_WIDTH;
            ECG_UNIT_POINTS.forEach(function (p, idx) {
                if (u > 0 && idx === 0) return; // shared join point with the previous unit
                pts.push((p[0] + offset) + "," + p[1]);
            });
        }
        return pts.join(" ");
    }

    /**
     * A looping ECG-style trace, one beat-shape scrolling through per
     * 60/bpm seconds — the same real average-BPM figure as the headline
     * above, just shown as a classic heart-monitor sweep. Drawn many units
     * wide (well beyond any realistic modal width) so the CSS animation's
     * fixed one-unit translateX loops seamlessly regardless of how many
     * units actually fit in view.
     */
    function buildEcgMonitor(bpm) {
        var svgNS = "http://www.w3.org/2000/svg";
        var units = 12;
        var width = units * ECG_UNIT_WIDTH;

        var monitor = el("div", "ecg-monitor");
        var track = el("div", "ecg-track");
        track.style.width = width + "px";
        track.style.animationDuration = Math.max(200, Math.round(60000 / bpm)) + "ms";

        var svg = document.createElementNS(svgNS, "svg");
        svg.setAttribute("width", width);
        svg.setAttribute("height", ECG_UNIT_HEIGHT);
        svg.setAttribute("viewBox", "0 0 " + width + " " + ECG_UNIT_HEIGHT);

        var polyline = document.createElementNS(svgNS, "polyline");
        polyline.setAttribute("points", buildEcgPoints(units));
        polyline.setAttribute("class", "ecg-trace");
        svg.appendChild(polyline);

        track.appendChild(svg);
        monitor.appendChild(track);
        return monitor;
    }

    function renderBpm(data) {
        modalBody.appendChild(el("div", "widget-headline", data.avg_bpm + " BPM"));
        modalBody.appendChild(buildEcgMonitor(data.avg_bpm));
        modalBody.appendChild(el("div", "widget-subtext", data.pulse_label));
        modalBody.appendChild(el("div", "widget-subtext",
            "Averaged from " + data.sample_size + " of your top tracks via Deezer's BPM data — Last.fm doesn't expose tempo itself."));
    }

    function renderBeforeFamous(data) {
        var list = el("ul", "distance-list");
        data.artists.forEach(function (a) {
            var li = document.createElement("li");
            li.appendChild(el("span", null, a.name));
            li.appendChild(el("span", "distance-count", a.listeners.toLocaleString() + " listeners"));
            list.appendChild(li);
        });
        modalBody.appendChild(list);
        modalBody.appendChild(el("div", "widget-subtext",
            "Ranked by lowest current global Last.fm listener count — a real signal of how under-the-radar they are, though Last.fm can't tell us exactly when you first found them."));
    }

    function renderObscurity(data) {
        modalBody.appendChild(el("div", "widget-headline", data.median_listeners.toLocaleString() + " median listeners"));
        modalBody.appendChild(el("div", "widget-subtext",
            "Average " + data.avg_listeners.toLocaleString() + " listeners across " + data.sample_size + " top artists"));
        modalBody.appendChild(el("div", "widget-subtext", data.label));
    }

    // --- Period pickers (Favourite Tracks, Trending, Genre Breakdown) ---

    function periodColor(name, i) {
        return name === "Other" ? "rgba(255,255,255,0.15)" : "hsl(" + ((i * 137.508) % 360) + ", 65%, 55%)";
    }

    function renderGenreContent(container, genres) {
        container.innerHTML = "";

        if (!genres || !genres.length) {
            container.appendChild(el("p", "empty-state", "Not enough tagged artists yet."));
            return;
        }

        var bar = el("div", "genre-bar");
        genres.forEach(function (g, i) {
            var seg = el("div", "genre-segment");
            seg.style.width = g.pct + "%";
            seg.style.background = periodColor(g.name, i);
            seg.title = g.name + " — " + g.pct + "%";
            bar.appendChild(seg);
        });
        container.appendChild(bar);

        var legend = el("ul", "genre-legend");
        genres.forEach(function (g, i) {
            var li = el("li", "genre-legend-item");
            li.setAttribute("data-pct", g.pct);
            var swatch = el("span", "genre-swatch");
            swatch.style.background = periodColor(g.name, i);
            li.appendChild(swatch);
            li.appendChild(el("span", "genre-name", g.name));
            li.appendChild(el("span", "genre-pct", g.pct + "%"));
            legend.appendChild(li);
        });
        container.appendChild(legend);

        applyGenreThreshold();
    }

    // Hides legend rows below the selected percentage threshold so a genre
    // breakdown with many small slices doesn't make the page too long — the
    // bar above stays untouched (it's already compact and accurate), only
    // the list is filtered. Re-applied after every genre re-render (period
    // switch), so the selected threshold persists across periods.
    var genreThresholdSelect = document.querySelector("[data-genre-threshold]");

    function applyGenreThreshold() {
        if (!genreThresholdSelect) {
            return;
        }
        var min = parseFloat(genreThresholdSelect.value) || 0;
        document.querySelectorAll(".genre-legend-item").forEach(function (li) {
            var pct = parseFloat(li.getAttribute("data-pct")) || 0;
            li.classList.toggle("genre-hidden", pct < min);
        });
    }

    if (genreThresholdSelect) {
        genreThresholdSelect.addEventListener("change", applyGenreThreshold);
        applyGenreThreshold();
    }

    function renderTrackListContent(container, tracks) {
        container.innerHTML = "";

        if (!tracks || !tracks.length) {
            container.appendChild(el("p", "empty-state", "No tracks for this period yet."));
            return;
        }

        var ol = document.createElement("ol");
        ol.className = "track-list";

        tracks.forEach(function (t) {
            var li = el("li", "track-row");
            li.setAttribute("data-artist", t.artist || "");
            li.setAttribute("data-track", t.name || "");
            li.appendChild(el("span", "rank", String(t.rank)));

            var thumb = el("span", "thumb");
            var initial = (t.name || "?").charAt(0).toUpperCase();
            if (t.art) {
                var img = document.createElement("img");
                img.src = t.art;
                img.alt = "";
                img.loading = "lazy";
                // Last.fm's API occasionally lists an image URL that 404s on
                // its own CDN — fall back to the letter placeholder on load
                // failure rather than showing a broken image.
                img.addEventListener("error", function () {
                    img.style.display = "none";
                    thumb.textContent = initial;
                });
                thumb.appendChild(img);
            } else {
                thumb.textContent = initial;
            }
            li.appendChild(thumb);

            var meta = el("span", "meta");
            meta.appendChild(el("div", "name", t.name));
            meta.appendChild(el("div", "artist", t.artist));
            li.appendChild(meta);

            var count = el("span", "count");
            count.appendChild(document.createTextNode(Number(t.playcount).toLocaleString() + " plays"));
            var bar = el("div", "bar");
            var fill = el("div", "bar-fill");
            fill.style.width = t.pct + "%";
            bar.appendChild(fill);
            count.appendChild(bar);
            li.appendChild(count);

            li.appendChild(el("span", "listen-links-hover"));

            ol.appendChild(li);
        });

        container.appendChild(ol);
    }

    var PERIOD_PICKERS = {
        genre: {
            url: function (period) { return "widgets.php?id=genre&period=" + encodeURIComponent(period); },
            render: function (container, data) { renderGenreContent(container, data.genres); },
        },
        favourites: {
            url: function (period) { return "widgets.php?id=tracks&panel=favourites&period=" + encodeURIComponent(period); },
            render: function (container, data) { renderTrackListContent(container, data.tracks); },
        },
        trending: {
            url: function (period) { return "widgets.php?id=tracks&panel=trending&period=" + encodeURIComponent(period); },
            render: function (container, data) { renderTrackListContent(container, data.tracks); },
        },
    };

    Object.keys(PERIOD_PICKERS).forEach(function (group) {
        var pickerConfig = PERIOD_PICKERS[group];
        var buttons = document.querySelectorAll('[data-period-group="' + group + '"]');
        var container = document.querySelector('[data-period-content="' + group + '"]');
        if (!buttons.length || !container) return;

        buttons.forEach(function (btn) {
            btn.addEventListener("click", function () {
                var period = btn.getAttribute("data-period");
                if (btn.classList.contains("active")) return;

                buttons.forEach(function (b) { b.classList.remove("active"); });
                btn.classList.add("active");

                container.innerHTML = "";
                container.appendChild(el("div", "widget-loading", "Loading…"));

                fetch(pickerConfig.url(period), { cache: "no-store" })
                    .then(function (res) { return res.json(); })
                    .then(function (payload) {
                        if (payload && payload.ok) {
                            pickerConfig.render(container, payload.data);
                        } else {
                            container.innerHTML = "";
                            container.appendChild(el("p", "empty-state", "Couldn't load this period."));
                        }
                    })
                    .catch(function () {
                        container.innerHTML = "";
                        container.appendChild(el("p", "empty-state", "Couldn't load this period."));
                    });
            });
        });
    });

    document.querySelectorAll("[data-widget-id]").forEach(function (card) {
        card.addEventListener("click", function () {
            loadWidget(card.getAttribute("data-widget-id"));
        });
    });

    if (modalClose) {
        modalClose.addEventListener("click", closeModal);
    }
    if (modalOverlay) {
        modalOverlay.addEventListener("click", function (evt) {
            if (evt.target === modalOverlay) {
                closeModal();
            }
        });
    }
    document.addEventListener("keydown", function (evt) {
        if (evt.key === "Escape") {
            closeModal();
        }
    });

    // --- Hover-triggered "listen on Spotify / YouTube Music" on track rows ---
    // Lazy: looked up once per unique track on first hover, not for every
    // row up front, since most of the 16+ rows across both lists will never
    // be hovered in a given visit.

    var listenLinksCache = {};

    var LISTEN_ICON_SVG = {
        spotify: '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/></svg>',
        youtube: '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M12 0C5.376 0 0 5.376 0 12s5.376 12 12 12 12-5.376 12-12S18.624 0 12 0zm0 19.104c-3.924 0-7.104-3.18-7.104-7.104S8.076 4.896 12 4.896s7.104 3.18 7.104 7.104-3.18 7.104-7.104 7.104zm0-13.332c-3.432 0-6.228 2.796-6.228 6.228S8.568 18.228 12 18.228s6.228-2.796 6.228-6.228S15.432 5.772 12 5.772zM9.684 15.54V8.46L15.816 12l-6.132 3.54z"/></svg>',
    };

    function buildHoverListenIcon(service, serviceName, linkData) {
        var a = document.createElement("a");
        a.className = "listen-icon listen-" + service;
        a.href = linkData.url;
        a.target = "_blank";
        a.rel = "noopener";
        a.title = (linkData.verified ? "Listen on " : "Search on ") + serviceName;
        a.innerHTML = LISTEN_ICON_SVG[service];
        return a;
    }

    function renderHoverListenLinks(container, links) {
        container.innerHTML = "";
        if (links.spotify && links.spotify.url) {
            container.appendChild(buildHoverListenIcon("spotify", "Spotify", links.spotify));
        }
        if (links.youtube && links.youtube.url) {
            container.appendChild(buildHoverListenIcon("youtube", "YouTube Music", links.youtube));
        }
    }

    function loadHoverListenLinks(row) {
        var container = row.querySelector("[data-listen-links-hover], .listen-links-hover");
        if (!container || container.getAttribute("data-loaded")) {
            return;
        }

        var artist = row.getAttribute("data-artist") || "";
        var track = row.getAttribute("data-track") || "";
        if (!artist || !track) {
            return;
        }

        container.setAttribute("data-loaded", "1");

        var cacheKey = artist.toLowerCase() + "|" + track.toLowerCase();
        if (listenLinksCache[cacheKey]) {
            renderHoverListenLinks(container, listenLinksCache[cacheKey]);
            return;
        }

        fetch("listen_links.php?artist=" + encodeURIComponent(artist) + "&track=" + encodeURIComponent(track), { cache: "no-store" })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                if (payload && payload.ok) {
                    listenLinksCache[cacheKey] = payload.links;
                    renderHoverListenLinks(container, payload.links);
                } else {
                    container.removeAttribute("data-loaded");
                }
            })
            .catch(function () {
                container.removeAttribute("data-loaded");
            });
    }

    document.addEventListener("mouseover", function (evt) {
        var row = evt.target.closest && evt.target.closest(".track-row");
        if (row) {
            loadHoverListenLinks(row);
        }
    });
})();
