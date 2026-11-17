/*
 * FILE PURPOSE
 * File   : public/assets/js/app_reveal.js
 * Feature: Core
 * Purpose: Scroll reveal animation for cards on app pages.
 */

// AstraTrack app screens (client, staff, admin): scroll reveal.
// Each outer card in the main content rises and fades in the first time it comes on screen, once.
// Cards that arrive together (a row of stats) follow each other 60ms apart. Inner cards, table rows and
// dialogs never move. The head script in app-layout sets html.reveal-app before the first paint (so
// cards below the fold start hidden) and drops it after 3s if this file never runs.
(() => {
    "use strict";
    const html = document.documentElement;
    if (!html.classList.contains("reveal-app")) return;
    window.__appReveal = true;

    const CARDS = ".card, .fcard, .stat, .cm-stat, .edit-card, .review-card, .start-card, .profile-card, .user-card, .users-card, .cm-record-card, .empty-state, .track";
    const main = document.getElementById("mainContent");
    if (!main || typeof Element.prototype.animate !== "function") {
        html.classList.remove("reveal-app");
        return;
    }
    // outermost cards only: a card inside another card (or in a dialog) moves with its parent, so it is
    // marked revealed at once (the layout's CSS hides every card until it carries .is-in)
    const all = [...main.querySelectorAll(CARDS)];
    const cards = all.filter((el) => !el.parentElement.closest(CARDS) && !el.closest("dialog"));
    all.forEach((el) => { if (!cards.includes(el)) el.classList.add("is-in"); });
    // from here only the watched cards (.rv) stay hidden; a card a screen draws later just shows
    cards.forEach((el) => el.classList.add("rv"));
    html.classList.add("reveal-ready");

    const ease = "cubic-bezier(.16, 1, .3, 1)";
    let batch = 0, batchTimer = 0;
    const io = new IntersectionObserver((entries) => {
        entries.forEach((e) => {
            if (!e.isIntersecting) return;
            io.unobserve(e.target);
            const delay = Math.min(batch++, 6) * 60;
            clearTimeout(batchTimer);
            batchTimer = setTimeout(() => { batch = 0; }, 120);
            e.target.classList.add("is-in");
            e.target.animate(
                [{ opacity: 0, transform: "translateY(16px)" }, { opacity: 1, transform: "none" }],
                { duration: 420, delay, easing: ease, fill: "backwards" }
            );
        });
    }, { threshold: 0.08, rootMargin: "0px 0px -4% 0px" });
    cards.forEach((el) => io.observe(el));
})();
