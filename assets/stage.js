"use strict";
(() => {
    const canPlay = new Audio().canPlayType('audio/ogg; codecs="opus"') !== ""
    const coarse = matchMedia("(pointer: coarse)").matches
    const audio = new Audio()
    audio.preload = "none"
    const peaksCache = new Map()
    const views = new Set()
    let current = null
    let queue = []
    let loaded = location.pathname + location.search
    const csrf = () => document.querySelector('meta[name="csrf"]')?.content ?? ""
    const escape = text => String(text).replace(/[&<>"']/g, char => ({"&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"})[char])
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
            headers: {"X-CSRF": csrf(), "Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams(fields)
        })
        const json = await response.json().catch(() => ({}))
        return {status: response.status, json}
    }
    const toast = text => {
        const element = document.createElement("p")
        element.className = "toast"
        element.setAttribute("role", "status")
        element.textContent = text
        document.body.append(element)
        setTimeout(() => {
            element.classList.add("closing")
            setTimeout(() => element.remove(), 300)
        }, 1600)
    }
    const trackOf = element => {
        const data = element.dataset
        return {
            uuid: data.uuid, src: data.src, peaks: data.peaks, duration: Number(data.duration) || 0,
            title: data.title, artist: data.artist, cover: data.cover, url: data.url, shareUrl: data.shareUrl
        }
    }
    const loadPeaks = url => {
        if (!peaksCache.has(url)) {
            peaksCache.set(url, fetch(url)
                .then(response => response.ok ? response.arrayBuffer() : Promise.reject())
                .then(buffer => new Int8Array(buffer))
                .catch(() => null))
        }
        return peaksCache.get(url)
    }
    const duration = () => current === null ? 0 : Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : current.duration
    const progressOf = uuid => current !== null && current.uuid === uuid && duration() > 0 ? Math.min(1, audio.currentTime / duration()) : 0
    const draw = (canvas, peaks, progress, hover) => {
        const width = canvas.clientWidth
        const height = canvas.clientHeight
        if (width === 0 || height === 0) {return}
        const ratio = window.devicePixelRatio || 1
        if (canvas.width !== Math.round(width * ratio) || canvas.height !== Math.round(height * ratio)) {
            canvas.width = Math.round(width * ratio)
            canvas.height = Math.round(height * ratio)
        }
        const context = canvas.getContext("2d")
        context.setTransform(ratio, 0, 0, ratio, 0, 0)
        context.clearRect(0, 0, width, height)
        if (peaks === null) {return}
        const style = getComputedStyle(canvas)
        const bar = parseFloat(style.getPropertyValue("--wave-bar")) || 2
        const step = bar + (parseFloat(style.getPropertyValue("--wave-gap")) || 1)
        const colors = {played: style.getPropertyValue("--wave-played"), hover: style.getPropertyValue("--wave-hover"), idle: style.getPropertyValue("--wave")}
        const pairs = peaks.length / 2
        const count = Math.max(1, Math.floor((width + step - bar) / step))
        for (let index = 0; index < count; index++) {
            const from = Math.floor(index / count * pairs)
            const to = Math.max(from + 1, Math.floor((index + 1) / count * pairs))
            let peak = 0
            for (let pair = from; pair < to && pair < pairs; pair++) {
                peak = Math.max(peak, -peaks[pair * 2], peaks[pair * 2 + 1])
            }
            const barHeight = Math.max(1, peak / 127 * height)
            const position = (index + 0.5) / count
            context.fillStyle = position <= progress ? colors.played : position <= hover ? colors.hover : colors.idle
            context.fillRect(index * step, (height - barHeight) / 2, bar, barHeight)
        }
    }
    const bar = {
        element: document.querySelector("[data-player-bar]"),
        peaks: null,
        hover: -1,
        part: name => bar.element?.querySelector(`[data-bar-${name}]`) ?? null
    }
    const render = () => {
        const playing = current !== null && !audio.paused
        views.forEach(view => {
            if (!view.element.isConnected) {
                views.delete(view)
                return
            }
            const isCurrent = current !== null && current.uuid === view.track.uuid
            view.element.classList.toggle("playing", isCurrent && playing)
            if (view.canvas !== null) {draw(view.canvas, view.peaks, progressOf(view.track.uuid), view.hover)}
            if (view.position !== null) {view.position.textContent = isCurrent ? formatTime(audio.currentTime) : view.position.dataset.total}
        })
        if (bar.element === null || current === null) {return}
        bar.element.classList.toggle("playing", playing)
        bar.element.classList.toggle("muted", audio.muted)
        bar.part("position").textContent = formatTime(audio.currentTime)
        draw(bar.part("waveform").querySelector("canvas"), bar.peaks, progressOf(current.uuid), bar.hover)
    }
    const loop = () => {
        render()
        if (!audio.paused) {requestAnimationFrame(loop)}
    }
    const seekTo = (track, ratio) => {
        if (current === null || current.uuid !== track.uuid) {select(track)}
        const seek = () => {
            if (duration() > 0) {audio.currentTime = ratio * duration()}
            render()
        }
        if (audio.readyState >= 1) {seek()} else {audio.addEventListener("loadedmetadata", seek, {once: true})}
        if (audio.paused) {audio.play().catch(() => render())}
    }
    const pageQueue = () => [...document.querySelectorAll("main [data-track]")]
        .filter(element => !element.classList.contains("hidden"))
        .map(trackOf)
        .filter((track, index, all) => all.findIndex(other => other.uuid === track.uuid) === index)
    const select = track => {
        current = track
        queue = pageQueue()
        if (!queue.some(entry => entry.uuid === track.uuid)) {queue = [track]}
        audio.src = track.src
        if (bar.element === null) {return}
        bar.element.classList.add("open")
        document.body.classList.add("has-player")
        bar.part("cover").src = track.cover
        bar.part("link").href = track.url
        bar.part("title").textContent = track.title
        bar.part("artist").textContent = track.artist
        bar.part("duration").textContent = formatTime(track.duration)
        bar.peaks = null
        loadPeaks(track.peaks).then(peaks => {
            if (current === track) {
                bar.peaks = peaks
                render()
            }
        })
    }
    const toggle = track => {
        if (current === null || current.uuid !== track.uuid) {select(track)}
        if (audio.paused) {audio.play().catch(() => render())} else {audio.pause()}
    }
    const step = direction => {
        if (current === null) {return}
        const index = queue.findIndex(entry => entry.uuid === current.uuid)
        const next = queue[index + direction]
        if (next !== undefined) {toggle(next)}
    }
    audio.addEventListener("play", () => requestAnimationFrame(loop))
    audio.addEventListener("pause", render)
    audio.addEventListener("seeked", render)
    audio.addEventListener("volumechange", render)
    audio.addEventListener("ended", () => {
        render()
        step(1)
    })
    const share = (title, url) => {
        if (coarse && typeof navigator.share === "function") {
            navigator.share({title, url}).catch(() => {})
            return
        }
        const text = encodeURIComponent(title)
        const link = encodeURIComponent(url)
        const targets = [
            ["Bluesky", "bluesky", `https://bsky.app/intent/compose?text=${text}%20${link}`],
            ["Mastodon", "mastodon", `https://mastodonshare.com/?text=${text}&url=${link}`],
            ["X", "x", `https://x.com/intent/post?text=${text}&url=${link}`],
            ["Facebook", "facebook", `https://www.facebook.com/sharer/sharer.php?u=${link}`],
            ["WhatsApp", "web", `https://wa.me/?text=${text}%20${link}`],
            ["Mail", "mail", `mailto:?subject=${text}&body=${link}`]
        ]
        const backdrop = document.createElement("div")
        backdrop.className = "sheet-backdrop"
        backdrop.innerHTML = `<div class="sheet" role="dialog" aria-modal="true" aria-label="Share">
            <div class="sheet-head"><b>Share ${escape(title)}</b><button class="button icon" type="button" data-close aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#icon-close"/></svg></button></div>
            <div class="share-list">${targets.map(([label, icon, href]) => `<a href="${escape(href)}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#icon-${icon}"/></svg>${label}</a>`).join("")}</div>
            <div class="copy-row"><input type="text" readonly value="${escape(url)}" aria-label="Link"><button class="button solid" type="button" data-copy-link>Copy</button></div>
        </div>`
        const close = () => {
            backdrop.remove()
            removeEventListener("keydown", onKey)
        }
        const onKey = event => {if (event.key === "Escape") {close()}}
        backdrop.addEventListener("click", async event => {
            if (event.target === backdrop || event.target.closest("[data-close]") !== null) {
                close()
            } else if (event.target.closest("[data-copy-link]") !== null) {
                const copied = await navigator.clipboard?.writeText(url).then(() => true, () => false)
                toast(copied ? "Link copied" : "Could not copy the link")
                close()
            } else if (event.target.closest("a") !== null) {
                close()
            }
        })
        addEventListener("keydown", onKey)
        document.body.append(backdrop)
        backdrop.querySelector("[data-copy-link]").focus()
    }
    const attachWaveform = (waveform, hoverTarget, onSeek, redraw) => {
        const ratioOf = event => {
            const rect = waveform.getBoundingClientRect()
            return Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width))
        }
        waveform.addEventListener("pointermove", event => {
            if (event.pointerType === "mouse") {
                hoverTarget.hover = ratioOf(event)
                redraw()
            }
        })
        waveform.addEventListener("pointerleave", () => {
            hoverTarget.hover = -1
            redraw()
        })
        waveform.addEventListener("pointerdown", event => onSeek(ratioOf(event)))
        new ResizeObserver(redraw).observe(waveform)
    }
    if (bar.element !== null) {
        bar.part("play").addEventListener("click", () => current !== null && toggle(current))
        bar.part("previous").addEventListener("click", () => audio.currentTime > 3 ? seekTo(current, 0) : step(-1))
        bar.part("next").addEventListener("click", () => step(1))
        bar.part("mute").addEventListener("click", () => audio.muted = !audio.muted)
        bar.part("share").addEventListener("click", () => current !== null && share(current.title, current.shareUrl))
        attachWaveform(bar.part("waveform"), bar, ratio => current !== null && seekTo(current, ratio), render)
    }
    const bindTracks = root => {
        root.querySelectorAll("[data-track]").forEach(element => {
            const waveform = element.querySelector("[data-waveform]")
            const view = {
                element, track: trackOf(element), peaks: null, hover: -1,
                canvas: waveform?.querySelector("canvas") ?? null,
                position: element.querySelector("[data-position]")
            }
            views.add(view)
            if (!canPlay) {
                element.querySelectorAll("[data-play]").forEach(button => button.disabled = true)
                waveform?.classList.add("unsupported")
                return
            }
            if (waveform === null) {return}
            loadPeaks(view.track.peaks).then(peaks => {
                view.peaks = peaks
                render()
            })
            attachWaveform(waveform, view, ratio => seekTo(view.track, ratio), render)
        })
        render()
    }
    const bindList = root => {
        const grid = root.querySelector("[data-grid]")
        if (grid === null) {return}
        const cards = [...grid.querySelectorAll("[data-track]")]
        const search = root.querySelector("[data-search]")
        const count = root.querySelector("[data-count]")
        const empty = root.querySelector("[data-no-results]")
        const tags = new Set()
        const initial = new URLSearchParams(location.search).get("tag")
        if (initial !== null && root.querySelector(`[data-tag-filter="${CSS.escape(initial)}"]`) !== null) {tags.add(initial)}
        let sort = "new"
        const apply = () => {
            const words = (search?.value ?? "").trim().toLowerCase().split(/\s+/).filter(word => word !== "")
            let visible = 0
            cards.forEach(card => {
                const cardTags = card.dataset.tags.split("\n").filter(tag => tag !== "")
                const text = `${card.dataset.title} ${card.dataset.artist} ${cardTags.join(" ")} ${card.dataset.bpm}`.toLowerCase()
                const show = (tags.size === 0 || cardTags.some(tag => tags.has(tag))) && words.every(word => text.includes(word))
                card.classList.toggle("hidden", !show)
                if (show) {visible++}
            })
            const ordered = [...cards].sort((a, b) => sort === "likes" && Number(b.dataset.likes) !== Number(a.dataset.likes)
                ? Number(b.dataset.likes) - Number(a.dataset.likes) : Number(b.dataset.created) - Number(a.dataset.created))
            ordered.forEach(card => grid.append(card))
            if (count !== null) {count.textContent = visible === cards.length ? count.dataset.total : `${visible} / ${count.dataset.total}`}
            empty?.classList.toggle("hidden", visible > 0)
            root.querySelectorAll("[data-tag-filter]").forEach(button => button.classList.toggle("active",
                button.dataset.tagFilter === "" ? tags.size === 0 : tags.has(button.dataset.tagFilter)))
            root.querySelectorAll("[data-sort]").forEach(button => button.classList.toggle("active", button.dataset.sort === sort))
        }
        root.querySelectorAll("[data-tag-filter]").forEach(button => button.addEventListener("click", () => {
            const tag = button.dataset.tagFilter
            if (tag === "") {tags.clear()} else if (tags.has(tag)) {tags.delete(tag)} else {tags.add(tag)}
            apply()
        }))
        root.querySelectorAll("[data-sort]").forEach(button => button.addEventListener("click", () => {
            sort = button.dataset.sort
            apply()
        }))
        search?.addEventListener("input", apply)
        apply()
        if (tags.size > 0) {root.querySelector("#tracks")?.scrollIntoView()}
    }
    const bindToast = element => {
        const close = () => {
            element.classList.add("closing")
            setTimeout(() => element.remove(), 300)
        }
        element.addEventListener("click", close)
        setTimeout(close, 10_000)
    }
    const bindForms = root => {
        root.querySelectorAll("[data-comment-form]").forEach(form => {
            const status = form.querySelector("[data-comment-status]")
            form.addEventListener("submit", async event => {
                event.preventDefault()
                const text = form.elements.text.value.trim()
                if (text === "") {return}
                const result = await post("comment", {track: form.dataset.track, text})
                if (result.status === 200) {
                    navigate(location.href, false, "comments")
                    return
                }
                status.classList.add("error")
                status.textContent = result.status === 429
                    ? `Please wait ${Math.ceil(result.json.seconds / 60)} minutes before your next comment.`
                    : result.status === 403 ? "You cannot comment here." : "Your comment could not be sent. Please try again."
            })
        })
        if (coarse) {
            root.querySelectorAll("[data-open]").forEach(link => {
                link.removeAttribute("href")
                link.classList.add("disabled")
                link.setAttribute("aria-disabled", "true")
                link.textContent = link.dataset.mobileText
            })
        }
        root.querySelectorAll("[data-toast]").forEach(bindToast)
        root.querySelectorAll("[data-image-form]").forEach(form => {
            const input = form.querySelector("[data-image-input]")
            const zoom = form.querySelector("[data-image-zoom]")
            const preview = form.querySelector(".preview")
            const width = Number(form.dataset.width)
            const height = Number(form.dataset.height)
            const canvas = document.createElement("canvas")
            canvas.width = width
            canvas.height = height
            const context = canvas.getContext("2d")
            context.imageSmoothingQuality = "high"
            const crop = {image: null, scale: 1, x: 0, y: 0}
            const fit = () => {
                const {image} = crop
                crop.x = Math.min(0, Math.max(width - image.width * crop.scale, crop.x))
                crop.y = Math.min(0, Math.max(height - image.height * crop.scale, crop.y))
                context.clearRect(0, 0, width, height)
                context.drawImage(image, crop.x, crop.y, image.width * crop.scale, image.height * crop.scale)
            }
            const baseScale = () => Math.max(width / crop.image.width, height / crop.image.height)
            const load = image => {
                crop.image = image
                crop.scale = baseScale()
                crop.x = (width - image.width * crop.scale) / 2
                crop.y = (height - image.height * crop.scale) / 2
                zoom.value = "1"
                zoom.disabled = false
                preview.replaceChildren(canvas)
                preview.classList.add("editing")
                fit()
            }
            const stored = preview.querySelector("img")
            if (stored !== null) {
                fetch(stored.src).then(response => response.blob()).then(blob => createImageBitmap(blob)).then(load).catch(() => {})
            }
            input.addEventListener("change", async () => {
                const file = input.files?.[0]
                if (file === undefined) {return}
                const image = await createImageBitmap(file).catch(() => null)
                if (image === null) {
                    toast("This image cannot be read.")
                    return
                }
                load(image)
            })
            zoom.addEventListener("input", () => {
                if (crop.image === null) {return}
                const next = baseScale() * Number(zoom.value)
                const centerX = (width / 2 - crop.x) / crop.scale
                const centerY = (height / 2 - crop.y) / crop.scale
                crop.scale = next
                crop.x = width / 2 - centerX * next
                crop.y = height / 2 - centerY * next
                fit()
            })
            canvas.addEventListener("pointerdown", event => {
                canvas.setPointerCapture(event.pointerId)
                const factor = width / canvas.clientWidth
                let lastX = event.clientX
                let lastY = event.clientY
                const move = moveEvent => {
                    crop.x += (moveEvent.clientX - lastX) * factor
                    crop.y += (moveEvent.clientY - lastY) * factor
                    lastX = moveEvent.clientX
                    lastY = moveEvent.clientY
                    fit()
                }
                const up = () => {
                    canvas.removeEventListener("pointermove", move)
                    canvas.removeEventListener("pointerup", up)
                    canvas.removeEventListener("pointercancel", up)
                }
                canvas.addEventListener("pointermove", move)
                canvas.addEventListener("pointerup", up)
                canvas.addEventListener("pointercancel", up)
            })
            form.addEventListener("submit", async event => {
                if (event.submitter?.value !== "image" || crop.image === null) {return}
                event.preventDefault()
                const pixels = context.getImageData(0, 0, width, height).data
                let transparent = false
                for (let index = 3; index < pixels.length && !transparent; index += 4) {transparent = pixels[index] < 255}
                const encode = (type, quality) => new Promise(resolve => canvas.toBlob(resolve, type, quality))
                let blob = transparent ? await encode("image/webp", 0.9) : await encode("image/jpeg", 0.88)
                if (transparent && blob.type !== "image/webp") {blob = await encode("image/png")}
                const data = new FormData(form)
                data.set("do", "image")
                data.set("image", blob, `image.${blob.type.split("/")[1]}`)
                await fetch(form.action, {method: "POST", body: data}).catch(() => null)
                location.replace("admin.php#settings-profile")
                location.reload()
            })
        })
    }
    const bind = root => {
        bindTracks(root)
        bindList(root)
        bindForms(root)
    }
    const isPageLink = link => {
        if (link.target !== "" || link.hasAttribute("download") || link.origin !== location.origin) {return false}
        const base = new URL("./", document.baseURI).pathname
        if (!link.pathname.startsWith(base)) {return false}
        const path = link.pathname.slice(base.length)
        if (/^(admin|api|setup|oauth)\.php$|^(media|assets|custom)\//.test(path)) {return false}
        return !(link.pathname === location.pathname && link.search === location.search && link.hash !== "")
    }
    const navigate = async (url, push, anchor = "") => {
        const response = await fetch(url, {credentials: "same-origin"}).catch(() => null)
        const html = response === null ? "" : await response.text().catch(() => "")
        const next = new DOMParser().parseFromString(html, "text/html")
        const main = next.querySelector("main[data-page]")
        if (main === null) {
            location.href = url
            return
        }
        const target = new URL(response.url || url, location.href)
        if (push) {history.pushState(null, "", target.href)}
        loaded = location.pathname + location.search
        document.title = next.title
        document.body.className = `${next.body.className}${current !== null ? " has-player" : ""}`
        const csrfMeta = next.querySelector('meta[name="csrf"]')
        if (csrfMeta !== null) {document.querySelector('meta[name="csrf"]')?.setAttribute("content", csrfMeta.content)}
        document.querySelector(".site-header")?.replaceWith(document.adoptNode(next.querySelector(".site-header")))
        document.querySelectorAll(".toast").forEach(element => element.remove())
        const adopted = document.adoptNode(main)
        document.querySelector("main").replaceWith(adopted)
        next.querySelectorAll("body > .toast").forEach(element => {
            const toastElement = document.adoptNode(element)
            adopted.before(toastElement)
            bindToast(toastElement)
        })
        bind(adopted)
        const hash = anchor !== "" ? anchor : target.hash.slice(1)
        const element = hash === "" ? null : document.getElementById(hash)
        if (element !== null) {element.scrollIntoView()} else {scrollTo(0, 0)}
    }
    document.addEventListener("click", async event => {
        if (event.defaultPrevented) {return}
        const target = event.target.closest("button, a")
        if (target === null) {return}
        const trackElement = target.closest("[data-track]")
        if (target.matches("[data-play]") && trackElement !== null) {
            toggle(trackOf(trackElement))
        } else if (target.matches("[data-share]") && trackElement !== null) {
            share(trackElement.dataset.title, trackElement.dataset.shareUrl)
        } else if (target.matches("[data-share-site]")) {
            share(target.dataset.title, target.dataset.url)
        } else if (target.matches("[data-like]")) {
            const {status, json} = await post("like", {track: target.dataset.like})
            if (status === 401) {
                location.href = target.dataset.login
            } else if (status === 200) {
                document.querySelectorAll(`[data-like="${CSS.escape(target.dataset.like)}"]`).forEach(button => {
                    button.classList.toggle("active", json.liked)
                    button.querySelector("[data-like-count]").textContent = json.likes
                    const card = button.closest("[data-likes]")
                    if (card !== null) {card.dataset.likes = json.likes}
                })
            }
        } else if (target.matches("[data-follow]")) {
            const {status, json} = await post("follow", {})
            if (status === 401) {
                location.href = target.dataset.login
            } else if (status === 200) {
                target.classList.toggle("active", json.following)
                target.textContent = json.following ? target.dataset.on : target.dataset.off
            }
        } else if (target.matches("[data-copy]")) {
            const copied = await navigator.clipboard?.writeText(target.dataset.copy).then(() => true, () => false)
            target.textContent = copied ? "Copied" : "Failed"
            setTimeout(() => target.textContent = "Copy", 1500)
        } else if (target.matches("[data-confirm]")) {
            if (!confirm(target.dataset.confirm)) {event.preventDefault()}
        } else if (target.matches("a[href]") && current !== null && event.button === 0
            && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey && isPageLink(target)) {
            event.preventDefault()
            navigate(target.href, true)
        }
    })
    addEventListener("popstate", () => {
        if (location.pathname + location.search !== loaded) {navigate(location.href, false)}
    })
    addEventListener("keydown", event => {
        if (event.key !== " " || current === null || event.target.closest("input, textarea, button, a, select") !== null) {return}
        event.preventDefault()
        toggle(current)
    })
    bind(document.body)
})()
