/** How near the end counts as at it, in pixels. */
const AT_END = 4
/** The slowest and the fastest the view scrolls after the output, in pixels per second. */
const MIN_SPEED = 120
const MAX_SPEED = 900
/** Below MAX_SPEED, how many times the distance left a second covers: a long way scrolls faster, never at once. */
const CATCH_UP = 3

/** A scrolling view that follows output as it arrives. */
export interface FollowScroll {
    /** Whether the view follows the output: false once the reader scrolled up, away from the end. */
    readonly following: boolean
    /** The output grew: scrolls to its end at a reading pace, when following. */
    grew(): void
    /** Follows the output again, from wherever the reader is: a new question, or a new answer. */
    resume(): void
    /** Stops scrolling and listening. */
    stop(): void
}

/**
 * Keeps $el scrolled to the end of its output while the output arrives,
 * gliding there rather than jumping when much arrives at once. Scrolling
 * up, away from the end, stops following until resume(), or until the
 * reader scrolls back to the end.
 */
export function followScroll(
    el: HTMLElement,
    frames: { request: (callback: FrameRequestCallback) => number, cancel: (handle: number) => void } = {
        request: callback => requestAnimationFrame(callback),
        cancel: handle => cancelAnimationFrame(handle),
    },
): FollowScroll {
    let following = true
    /** Where the view was when last seen, to tell a reader scrolling up from the view's own scrolling. */
    let last = el.scrollTop
    /** Where the glide is, unrounded: scrollTop may round what it is given. */
    let position = el.scrollTop
    let frame: number | null = null
    let then: number | null = null

    const end = () => Math.max(0, el.scrollHeight - el.clientHeight)

    function halt() {
        if (frame !== null) frames.cancel(frame)
        frame = null
        then = null
    }

    function tick(now: number) {
        frame = null
        const left = end() - position
        if (!following || left <= 0.5) {
            then = null
            return
        }
        const seconds = then === null ? 1 / 60 : Math.min((now - then) / 1000, 0.1)
        then = now
        const speed = Math.min(MAX_SPEED, Math.max(MIN_SPEED, left * CATCH_UP))
        position = Math.min(end(), position + speed * seconds)
        el.scrollTop = position
        last = el.scrollTop
        frame = frames.request(tick)
    }

    function grew() {
        if (!following || frame !== null) return
        position = el.scrollTop
        last = position
        frame = frames.request(tick)
    }

    function onScroll() {
        const top = el.scrollTop
        // the view's own scrolling, which already set where it is
        if (Math.abs(top - last) <= 1) return
        if (end() - top <= AT_END) following = true
        else if (top < last) {
            // the reader scrolled up, away from the end
            following = false
            halt()
        }
        last = top
        position = top
    }

    el.addEventListener('scroll', onScroll, { passive: true })

    return {
        get following() {
            return following
        },
        grew,
        resume() {
            following = true
            grew()
        },
        stop() {
            halt()
            el.removeEventListener('scroll', onScroll)
        },
    }
}
