"use strict";
(() => {
    const csrf = document.querySelector('meta[name="csrf"]')?.content ?? ""
    const canPlay = new Audio().canPlayType('audio/ogg; codecs="opus"') !== ""
    const audio = new Audio()
    audio.preload = "none"
    let current = null
    const duration = () => current !== null && !Number.isFinite(audio.duration) ? current.duration : audio.duration
    const formatTime = seconds => {
        const total = Math.max(0, Math.floor(seconds))
        const hours = Math.floor(total / 3600)
        const minutes = Math.floor(total % 3600 / 60)
        const rest = String(total % 60).padStart(2, "0")
        return hours > 0 ? `${hours}:${String(minutes).padStart(2, "0")}:${rest}` : `${minutes}:${rest}`
    }
    const post = async (action, fields) => {
        const response = await fetch(`api.php?action=${action}`, {
            method: "POST",
            headers: {"X-CSRF": csrf, "Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams(fields)
        })
        const json = await response.json().catch(() => ({}))
        return {status: response.status, json}
    }
    const draw = player => {
        const {canvas, peaks} = player
        const width = canvas.clientWidth
        const height = canvas.clientHeight
        if (width === 0 || height === 0) {return}
        const ratio = window.devicePixelRatio || 1
        if (canvas.width !== Math.round(width * ratio) || canvas.height !== Math.round(height * ratio)) {
            canvas.width = Math.round(width * ratio)
            canvas.height = Math.round(height * ratio)
        }
        const context = canvas.getContext("2d")
        const style = getComputedStyle(canvas)
        context.setTransform(ratio, 0, 0, ratio, 0, 0)
        context.clearRect(0, 0, width, height)
        if (peaks === null) {return}
        const progress = player === current && duration() > 0 ? Math.min(1, audio.currentTime / duration()) : 0
        const pairs = peaks.length / 2
        const bar = 2
        const step = 3
        const center = height / 2
        for (let x = 0; x < width; x += step) {
            const from = Math.floor(x / width * pairs)
            const to = Math.max(from + 1, Math.floor((x + step) / width * pairs))
            let min = 0
            let max = 0
            for (let index = from; index < to && index < pairs; index++) {
                min = Math.min(min, peaks[index * 2])
                max = Math.max(max, peaks[index * 2 + 1])
            }
            const top = Math.max(1, max / 127 * center)
            const bottom = Math.max(1, -min / 127 * center)
            context.fillStyle = x / width < progress ? style.getPropertyValue("--wave-played") : style.getPropertyValue("--wave")
            context.fillRect(x, center - top, bar, top + bottom)
        }
        if (progress > 0) {
            context.fillStyle = style.getPropertyValue("--wave-cursor")
            context.fillRect(Math.round(progress * width), 0, 1, height)
        }
    }
    const update = () => {
        if (current === null) {return}
        draw(current)
        if (current.position !== null) {current.position.textContent = formatTime(audio.currentTime)}
    }
    const loop = () => {
        update()
        if (!audio.paused) {requestAnimationFrame(loop)}
    }
    const select = player => {
        if (current === player) {return}
        if (current !== null) {
            current.element.classList.remove("playing")
            const previous = current
            current = null
            draw(previous)
            if (previous.position !== null) {previous.position.textContent = "0:00"}
        }
        current = player
        audio.src = player.element.dataset.src
    }
    const toggle = player => {
        if (current !== player) {select(player)}
        if (audio.paused) {
            audio.play().catch(() => player.element.classList.remove("playing"))
        } else {
            audio.pause()
        }
    }
    audio.addEventListener("play", () => {
        current?.element.classList.add("playing")
        requestAnimationFrame(loop)
    })
    audio.addEventListener("pause", () => {
        current?.element.classList.remove("playing")
        update()
    })
    audio.addEventListener("ended", () => {
        current?.element.classList.remove("playing")
        update()
    })
    audio.addEventListener("seeked", update)
    document.querySelectorAll("[data-player]").forEach(element => {
        const waveform = element.querySelector("[data-waveform]")
        const player = {
            element,
            canvas: waveform.querySelector("canvas"),
            button: element.querySelector("[data-play]"),
            position: element.querySelector("[data-position]"),
            duration: Number(element.dataset.duration) || 0,
            peaks: null
        }
        if (!canPlay) {
            player.button.disabled = true
            waveform.classList.add("unsupported")
            return
        }
        fetch(element.dataset.peaks)
            .then(response => response.ok ? response.arrayBuffer() : Promise.reject())
            .then(buffer => {
                player.peaks = new Int8Array(buffer)
                draw(player)
            })
            .catch(() => {})
        player.button.addEventListener("click", () => toggle(player))
        waveform.addEventListener("pointerdown", event => {
            const rect = waveform.getBoundingClientRect()
            const ratio = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width))
            if (current !== player) {select(player)}
            const seek = () => {
                if (duration() > 0) {audio.currentTime = ratio * duration()}
                update()
            }
            if (audio.readyState >= 1) {seek()} else {audio.addEventListener("loadedmetadata", seek, {once: true})}
            if (audio.paused) {audio.play().catch(() => {})}
        })
        new ResizeObserver(() => draw(player)).observe(waveform)
    })
    document.querySelectorAll("[data-like]").forEach(button => {
        button.addEventListener("click", async () => {
            const {status, json} = await post("like", {track: button.dataset.like})
            if (status === 401) {
                location.href = button.dataset.login
            } else if (status === 200) {
                button.classList.toggle("active", json.liked)
                button.querySelector("[data-like-count]").textContent = json.likes
            }
        })
    })
    document.querySelectorAll("[data-follow]").forEach(button => {
        button.addEventListener("click", async () => {
            const {status, json} = await post("follow", {})
            if (status === 401) {
                location.href = button.dataset.login
            } else if (status === 200) {
                button.classList.toggle("active", json.following)
                button.textContent = json.following ? button.dataset.on : button.dataset.off
            }
        })
    })
    document.querySelectorAll("[data-comment-form]").forEach(form => {
        const status = form.querySelector("[data-comment-status]")
        form.addEventListener("submit", async event => {
            event.preventDefault()
            const text = form.elements.text.value.trim()
            if (text === "") {return}
            const result = await post("comment", {track: form.dataset.track, text})
            if (result.status === 200) {
                location.reload()
                return
            }
            status.classList.add("error")
            status.textContent = result.status === 429
                ? `Please wait ${Math.ceil(result.json.seconds / 60)} minutes before your next comment.`
                : result.status === 403 ? "You cannot comment here." : "Your comment could not be sent. Please try again."
        })
    })
    if (matchMedia("(pointer: coarse)").matches) {
        document.querySelectorAll("[data-open]").forEach(link => {
            link.removeAttribute("href")
            link.classList.add("disabled")
            link.setAttribute("aria-disabled", "true")
            link.textContent = link.dataset.mobileText
        })
    }
    document.querySelectorAll("[data-confirm]").forEach(button => {
        button.addEventListener("click", event => {
            if (!confirm(button.dataset.confirm)) {event.preventDefault()}
        })
    })
})()
