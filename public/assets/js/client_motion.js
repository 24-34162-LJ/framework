// AstraTrack client portal: the motion that needs a script (client_motion.css has the rest).
// - "Show more" sections open with a height reveal (closing stays instant: exits are quicker than entrances)
// - the shipment popup's progress list arrives as a short cascade each time it is filled
// - a Track error nudges the search box sideways, every time it is shown
// Nothing runs under reduced motion.
(() => {
    "use strict";
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    const ease = "cubic-bezier(.16, 1, .3, 1)";
    const canAnimate = typeof Element.prototype.animate === "function";
    if (!canAnimate) return;

    /* ---------- collapse sections: reveal on open ---------- */
    document.querySelectorAll(".collapse-body").forEach((body) => {
        new MutationObserver(() => {
            if (body.hidden) return;
            const h = body.scrollHeight;
            const prev = body.style.overflow;
            body.style.overflow = "hidden";
            body.animate(
                [{ height: "0px", opacity: 0 }, { height: h + "px", opacity: 1 }],
                { duration: 300, easing: ease }
            ).onfinish = () => { body.style.overflow = prev; };
        }).observe(body, { attributes: true, attributeFilter: ["hidden"] });
    });

    /* ---------- shipment popup: progress list cascade ---------- */
    const modal = document.getElementById("detailModal");
    const timeline = document.getElementById("modalTimeline");
    if (modal && timeline) {
        new MutationObserver(() => {
            if (!modal.open) return;
            [...timeline.children].forEach((li, i) => {
                li.animate(
                    [{ opacity: 0, transform: "translateY(6px)" }, { opacity: 1, transform: "none" }],
                    { duration: 280, delay: 80 + Math.min(i, 8) * 45, easing: ease, fill: "backwards" }
                );
            });
        }).observe(timeline, { childList: true });
    }

    /* ---------- Track: nudge the search box on an error ---------- */
    const trackError = document.getElementById("trackError");
    const trackInput = document.querySelector(".track-input");
    if (trackError && trackInput) {
        new MutationObserver(() => {
            if (trackError.hidden || !trackError.textContent.trim()) return;
            trackInput.animate(
                [{ transform: "translateX(0)" }, { transform: "translateX(-6px)" }, { transform: "translateX(5px)" }, { transform: "translateX(-3px)" }, { transform: "translateX(0)" }],
                { duration: 320, easing: "ease-out" }
            );
        }).observe(trackError, { attributes: true, attributeFilter: ["hidden"], childList: true });
    }
})();
