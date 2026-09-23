/**
 * Звук уведомления: мелодичный 2-тональный chime (Web Audio) + опциональный MP3.
 * Unlock без обрыва: не play→pause на том же Audio-элементе.
 */
(function (global) {
    const SOUND_URLS = [
        '/crm/sounds/order-notify.mp3',
        '/sounds/order-notify.mp3',
    ];

    let chime = null;
    let chimeUrl = '';
    let audioCtx = null;
    let unlocked = false;
    let playingUntil = 0;

    function pickSoundUrl(custom) {
        return custom || SOUND_URLS[0];
    }

    function ensureChime(url) {
        const target = pickSoundUrl(url);
        if (chime && chimeUrl === target) {
            return chime;
        }
        chimeUrl = target;
        chime = new Audio(target);
        chime.preload = 'auto';
        chime.volume = 1;
        return chime;
    }

    function ensureAudioContext() {
        if (!audioCtx) {
            const Ctx = global.AudioContext || global.webkitAudioContext;
            if (!Ctx) {
                return Promise.resolve(null);
            }
            audioCtx = new Ctx();
        }
        if (audioCtx.state === 'suspended') {
            return audioCtx.resume().then(function () { return audioCtx; }).catch(function () { return audioCtx; });
        }
        return Promise.resolve(audioCtx);
    }

    /** Двухтональный сигнал ~1.25 с — основной звук (не зависит от MP3). */
    function playChimeTone() {
        return ensureAudioContext().then(function (ctx) {
            if (!ctx) {
                return false;
            }
            const now = ctx.currentTime;
            const master = ctx.createGain();
            master.gain.setValueAtTime(0.0001, now);
            master.gain.exponentialRampToValueAtTime(0.45, now + 0.02);
            master.gain.setValueAtTime(0.45, now + 0.55);
            master.gain.exponentialRampToValueAtTime(0.0001, now + 1.2);
            master.connect(ctx.destination);

            function tone(freq, start, dur) {
                const osc = ctx.createOscillator();
                const g = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                g.gain.setValueAtTime(0.0001, start);
                g.gain.exponentialRampToValueAtTime(1, start + 0.015);
                g.gain.exponentialRampToValueAtTime(0.0001, start + dur);
                osc.connect(g);
                g.connect(master);
                osc.start(start);
                osc.stop(start + dur + 0.02);
            }

            // две ноты: A5 → E6 (как короткий «динг-дон»)
            tone(880, now, 0.55);
            tone(1318.5, now + 0.28, 0.75);

            playingUntil = Date.now() + 1300;
            return true;
        }).catch(function () { return false; });
    }

    function playMp3(url) {
        const audio = ensureChime(url);
        try {
            audio.pause();
            audio.currentTime = 0;
        } catch (e) {}
        return audio.play().then(function () {
            playingUntil = Date.now() + 2500;
            return true;
        }).catch(function () { return false; });
    }

    /** Тихий unlock без слышимого обрыва. */
    function unlock(url) {
        ensureChime(url);
        return ensureAudioContext()
            .then(function (ctx) {
                const audio = ensureChime(url);
                const prev = audio.volume;
                audio.volume = 0.001;
                return audio.play().then(function () {
                    try {
                        audio.pause();
                        audio.currentTime = 0;
                    } catch (e) {}
                    audio.volume = prev;
                    return true;
                }).catch(function () {
                    audio.volume = prev;
                    // достаточно resume AudioContext
                    return !!ctx;
                });
            })
            .then(function () {
                unlocked = true;
                return true;
            })
            .catch(function () {
                unlocked = true;
                return true;
            });
    }

    function play(url) {
        const run = function () {
            // Сначала надёжный Web Audio chime (полный ~1.25с)
            return playChimeTone().then(function (ok) {
                if (ok) {
                    return 'chime';
                }
                return playMp3(url).then(function (mp3Ok) { return mp3Ok ? 'mp3' : false; });
            });
        };
        if (!unlocked) {
            return unlock(url).then(run);
        }
        // не режем уже играющий сигнал повторным вызовом
        if (Date.now() < playingUntil) {
            return Promise.resolve('playing');
        }
        return run();
    }

    function bindUnlock(url) {
        const once = function () { unlock(url); };
        ['pointerdown', 'click', 'keydown', 'touchstart'].forEach(function (evt) {
            document.addEventListener(evt, once, { once: true, passive: true });
        });
    }

    global.LcNotifySound = {
        unlock: unlock,
        play: play,
        bindUnlock: bindUnlock,
        isUnlocked: function () { return unlocked; },
    };
})(window);
