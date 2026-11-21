/*
 * FILE PURPOSE
 * File   : public/assets/js/user_menu.js
 * Feature: Core
 * Purpose: Closes the top-right account menu (<details data-user-menu>) on outside click or Escape.
 */

(() => {
    const menus = () => document.querySelectorAll("details[data-user-menu][open]");
    document.addEventListener("click", (event) => {
        menus().forEach((menu) => { if (!menu.contains(event.target)) menu.open = false; });
    });
    document.addEventListener("keydown", (event) => {
        if (event.key !== "Escape") return;
        menus().forEach((menu) => { menu.open = false; menu.querySelector("summary")?.focus(); });
    });
})();
