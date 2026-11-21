import { describe, expect, it } from 'vitest'
import { followScroll } from '../../app/utils/followScroll'

/** A scrolling view of $height pixels over output of $scrollHeight, and frames run by hand. */
function view(scrollHeight: number, height = 400) {
    const el = document.createElement('div')
    let top = 0
    const size = { scrollHeight }
    Object.defineProperty(el, 'scrollHeight', { get: () => size.scrollHeight })
    Object.defineProperty(el, 'clientHeight', { get: () => height })
    Object.defineProperty(el, 'scrollTop', {
        get: () => top,
        set: (value: number) => {
            top = Math.round(Math.max(0, Math.min(value, size.scrollHeight - height)))
        },
    })
    const queue: FrameRequestCallback[] = []
    let now = 0
    const frames = {
        request: (callback: FrameRequestCallback) => queue.push(callback),
        cancel: () => queue.splice(0),
    }
    return {
        el,
        size,
        frames,
        /** Runs $count frames, 1/60 s apart, and says how many were asked for. */
        run(count = 1) {
            let ran = 0
            for (let i = 0; i < count && queue.length > 0; i++) {
                now += 1000 / 60
                queue.shift()!(now)
                ran++
            }
            return ran
        },
        /** The reader scrolls to $to. */
        scroll(to: number) {
            el.scrollTop = to
            el.dispatchEvent(new Event('scroll'))
        },
        /** The view's own scroll event, after a frame set scrollTop. */
        echo() {
            el.dispatchEvent(new Event('scroll'))
        },
    }
}

describe('followScroll', () => {
    it('glides to the end of a large chunk at a reading pace, never at once', () => {
        const v = view(5400)
        const follow = followScroll(v.el, v.frames)
        follow.grew()
        v.run()
        v.echo()

        // one frame covers at most 900 px/s
        expect(v.el.scrollTop).toBeGreaterThan(0)
        expect(v.el.scrollTop).toBeLessThanOrEqual(16)

        v.run(60 * 10)
        expect(v.el.scrollTop).toBe(5000)
        expect(follow.following).toBe(true)
        expect(v.run()).toBe(0) // done: no frame left asked for
    })

    it('stops following when the reader scrolls up, and the next output does not move the view', () => {
        const v = view(2400)
        const follow = followScroll(v.el, v.frames)
        follow.grew()
        v.run(30)
        v.echo()
        const at = v.el.scrollTop

        v.scroll(at - 200)
        expect(follow.following).toBe(false)
        expect(v.run()).toBe(0)

        v.size.scrollHeight = 4000
        follow.grew()
        expect(v.run(100)).toBe(0)
        expect(v.el.scrollTop).toBe(at - 200)
    })

    it('follows again on resume, a new question or answer', () => {
        const v = view(2400)
        const follow = followScroll(v.el, v.frames)
        v.scroll(1000)
        v.scroll(500)
        expect(follow.following).toBe(false)

        follow.resume()
        expect(follow.following).toBe(true)
        v.run(600)
        expect(v.el.scrollTop).toBe(2000)
    })

    it('follows again when the reader scrolls back to the end', () => {
        const v = view(2400)
        const follow = followScroll(v.el, v.frames)
        v.scroll(1000)
        v.scroll(500)
        expect(follow.following).toBe(false)

        v.scroll(2000)
        expect(follow.following).toBe(true)
        v.size.scrollHeight = 2800
        follow.grew()
        v.run(600)
        expect(v.el.scrollTop).toBe(2400)
    })

    it('stops listening when stopped', () => {
        const v = view(2400)
        const follow = followScroll(v.el, v.frames)
        follow.grew()
        follow.stop()
        expect(v.run()).toBe(0)
        v.scroll(1000)
        v.scroll(500)
        expect(follow.following).toBe(true)
    })
})
