{{--
    FILE PURPOSE
    File   : resources/views/landing/index.blade.php
    Feature: Landing page
    Purpose: Public landing (home) page.
--}}

<!doctype html>
<html lang="en" data-type="service" data-radius="soft" data-buttons="tonal" data-motion>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Astra Cargo: customs broker and trucking in Imus, Cavite</title>
    <meta name="description"
        content="Astra Cargo Impex Solutions, a customs broker in Imus, Cavite since 2023: customs clearance, tariff classification, documents, duties and trucking, with every step in a client portal.">
    <meta name="site-kind" content="other">
    <meta name="page-type" content="service">
    <link rel="icon" type="image/png" href="{{ asset('assets/images/favicon-32.png') }}?v=2">
    <script>
        // scroll reveal: set before the first paint so content below the hero starts hidden instead of flashing.
        // If the reveal script never starts (blocked, failed), the page shows everything after 3s.
        (function() {
            var html = document.documentElement;
            if (html.getAttribute('data-motion') === 'off' || !('IntersectionObserver' in window) || matchMedia(
                    '(prefers-reduced-motion: reduce)').matches) return;
            html.classList.add('reveal-on');
            setTimeout(function() {
                if (!window.__reveal) html.classList.remove('reveal-on');
            }, 3000);
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400..700&family=Libre+Franklin:wght@400..600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/taste/system.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/taste/ui/cards.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/taste/motion/motion.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/taste/layout/layout.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/taste/icons/icon.css') }}">
    <style>
        :root {
            --p-bg: #f0f3fa;
            --p-surface: #d5deef;
            --p-ink: #1e2f4d;
            --p-muted: #4f5f78;
            --p-accent: #395886;
            --p-accent-ink: #f0f3fa;
            --p-action: #395886;
            --p-action-ink: #f0f3fa;
            --p-inv-bg: #1e2f4d;
            --p-inv-surface: #2b4269;
            --p-inv-ink: #f0f3fa;
            --p-inv-muted: #b1c9ef;
            --p-inv-accent: #f0f3fa;
            --p-inv-accent-ink: #1e2f4d;
            --p-inv-action: #f0f3fa;
            --p-inv-action-ink: #1e2f4d;
            --font-display: "Source Serif 4", Georgia, serif;
            --font-text: "Libre Franklin", system-ui, sans-serif;
            --display-weight: 400;
            --display-tracking: -0.02em;
            --display-tracking-xl: -0.026em;
        }

        /* the backdrop behind the product cards (process and portal panels): the design system defaults to
   sand (#d9ccb7); use the site's blue palette instead */
        :root:root,
        :root[data-ground] {
            --ground-bg: #b1c9ef;
            --ground-surface: #d5deef;
            --ground-ink: #1e2f4d;
            --ground-muted: #4f5f78;
            --ground-accent: #395886;
        }
    </style>
    <link rel="stylesheet" href="{{ asset('assets/taste/ui/base.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/taste/ui/files/fragment.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/taste/ui/notify/fragment.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/taste/ui/checklist/fragment.css') }}">
    <!-- page CSS: only what sits inside a visual and the kit lacks -->
    <style>
        .nav__logo img {
            width: 32px;
            height: 32px;
            object-fit: contain;
        }

        #process .panel {
            --p-h: 300px;
        }

        @media (min-width: 640px) and (max-width: 1023px) {
            #process {
                --h: 2
            }
        }

        @media (min-width: 1024px) and (max-width: 1279px) {
            #process .card.span-4 {
                grid-column: span 4
            }
        }

        @media (max-width: 1023px) {
            .hero-visual {
                --ratio: 66%;
            }

            #services .frame {
                --ratio: 50%;
            }

            #about .frame {
                --ratio: 56%;
            }

            #portal .frame {
                --ratio: 64%;
            }

            #process .panel {
                --p-h: 420px;
            }

            #services .rows>li>p {
                display: none;
            }

            .close--type .btn--xl {
                width: auto;
                align-self: flex-start;
            }
        }

        @media (max-width: 639px) {

            #services .rows>li>p,
            #portal .portal-points {
                display: none;
            }

            #services .frame {
                --ratio: 56%;
            }

            #about .frame {
                --ratio: 56%;
            }

            #portal .frame {
                --ratio: 92%;
            }

            #process .panel {
                --p-h: 330px;
            }

            .close--type .btn--xl {
                width: 100%;
                align-self: stretch;
            }
        }

        .close--type .btn--xl {
            flex: none;
        }

        /* one rhythm on desktop: every split section and the process row run to the screen's foot (--padb),
     so each section ends on the same line instead of leaving 240px+ of ground under a short frame */
        @media (min-width: 1024px) {
            :is(#services, #about, #portal) .wrap>.grid {
                flex: 1 1 auto;
                min-height: 0;
                align-items: stretch;
            }

            :is(#services, #about, #portal) .frame--split {
                height: 100%;
            }

            #process .wrap>.grid {
                flex: 1 1 auto;
                min-height: 0;
                align-items: stretch;
            }

            #process .card {
                display: flex;
                flex-direction: column;
                height: 100%;
            }

            #process .card>.panel {
                flex: 1 1 auto;
                height: auto;
                min-height: var(--p-h);
            }
        }

        /* a desktop screen under 900px tall holds the six service names, not their one-line notes */
        @media (min-width: 1024px) and (max-height: 899px) {
            #services .rows>li>p {
                display: none;
            }
        }

        /* tablet close: the button sized to its words, not a full-width band; the footer's three link columns share a row */
        @media (min-width: 640px) and (max-width: 1023px) {
            .closing__cta.close--type>.btn--xl {
                align-self: flex-start;
                width: auto;
                height: var(--btn-h);
                padding-inline: var(--s-5);
                font: 500 var(--t-body)/1 var(--font-text);
                letter-spacing: 0;
            }

            .closing__links .grid> :first-child {
                grid-column: 1 / -1;
            }

            .closing__links .grid>.footer__col {
                grid-column: span 4;
            }
        }

        /* the facts row returns on phones from 375 by 800px; smaller, it would push the photo off the first screen (the footer keeps the address and phone) */
        @media (min-width: 375px) and (max-width: 639px) and (min-height: 800px) {
            .hero .hero-facts {
                display: grid;
                grid-template-columns: auto minmax(0, 1fr) auto;
                gap: var(--s-4);
                margin-top: var(--s-4);
            }

            .hero .hero-facts dd a {
                white-space: nowrap;
            }
        }

        /* timestamps in the notify fragment: the kit's --u-soft is 3.4:1 on white; muted is 6:1 */
        #process .ui-note__title time {
            color: var(--u-muted);
        }

        @media (max-width: 639px) {
            .footer__col a {
                display: inline-flex;
                align-items: center;
                min-height: 44px;
            }

            .footer__col li+li {
                margin-top: 0;
            }
        }

        html {
            scrollbar-color: color-mix(in oklab, #1e2f4d 32%, transparent) transparent;
        }

        .ui-delivery {
            width: 100%;
            font-size: 13px;
        }

        .ui-delivery__rows {
            display: grid;
            gap: 0;
            margin: 0;
            padding: 0 14px 6px;
            list-style: none;
        }

        .ui-delivery__rows li {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--u-line);
        }

        .ui-delivery__rows li:last-child {
            border-bottom: 0;
        }

        .ui-delivery__rows span:first-child {
            color: var(--u-muted);
        }

        /* ---------------------------------------------------------------- motion
     one authored moment: the hero photograph opens like a container door (a clip from the left
     edge across) while the picture settles from 6% larger, and the headline comes into focus beside it.
     Everything rests on its finished picture: the keyframes only run from a hidden start to it. */
        @keyframes hero-door {
            from {
                clip-path: inset(0 100% 0 0);
            }

            to {
                clip-path: inset(0 0 0 0);
            }
        }

        @keyframes hero-settle {
            from {
                transform: scale(1.06);
            }
        }

        @keyframes hero-focus {
            from {
                opacity: 0;
                filter: blur(8px);
            }
        }

        @keyframes hero-in {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
        }

        @keyframes hero-fade {
            from {
                opacity: 0;
            }
        }

        @media (prefers-reduced-motion: no-preference) {
            html:not([data-motion="off"]) .hero-visual {
                animation: hero-door 900ms cubic-bezier(.77, 0, .18, 1) 80ms both;
            }

            html:not([data-motion="off"]) .hero-visual>.frame__img {
                animation: hero-settle 1400ms cubic-bezier(.16, 1, .3, 1) 80ms both;
            }

            html:not([data-motion="off"]) .hero #hero-h {
                animation: hero-focus 800ms cubic-bezier(.16, 1, .3, 1) 160ms both;
            }

            html:not([data-motion="off"]) .hero :is(.hero__lead, .btn-row, .hero-facts) {
                animation: hero-in 600ms cubic-bezier(.16, 1, .3, 1) both;
            }

            html:not([data-motion="off"]) .hero .hero__lead {
                animation-delay: 380ms;
            }

            html:not([data-motion="off"]) .hero .btn-row {
                animation-delay: 480ms;
            }

            html:not([data-motion="off"]) .hero .hero-facts {
                animation-delay: 580ms;
            }
        }

        /* scroll reveal: below the hero, each part pops into place as it scrolls into view. Words and rows rise,
     the three step cards pop in turn. Siblings stagger by
     --d (set in the script, capped). Only under .reveal-on, which the head script sets. */
        .reveal-on :is(.section .section-head, .section .t-lead, .section .span-6 > p, .rows > li, .section .card--open, .closing__cta, .closing__links .grid > *, .footer__legal) {
            opacity: 0;
            transform: translateY(28px) scale(.98);
            transition: opacity 600ms cubic-bezier(.16, 1, .3, 1), transform 800ms cubic-bezier(.16, 1, .3, 1);
            transition-delay: var(--d, 0ms);
        }

        .reveal-on .section .card--open {
            transform: translateY(40px) scale(.94);
        }

        /* the pictures (the hero's included) pop in like the rest, once: the frame rises and grows into place
     while the photograph inside settles from 8% larger. The hero's load keyframes give way to this. */
        html.reveal-on .hero-visual,
        html.reveal-on .hero-visual>.frame__img {
            animation: none;
        }

        .reveal-on :is(.hero-visual, .section .frame--split) {
            opacity: 0;
            transform: translateY(48px) scale(.94);
            transition: opacity 700ms cubic-bezier(.16, 1, .3, 1), transform 1000ms cubic-bezier(.16, 1, .3, 1);
            transition-delay: var(--d, 0ms);
        }

        .reveal-on :is(.hero-visual, .section .frame--split)>.frame__img {
            transform: scale(1.08);
            transition: transform 1400ms cubic-bezier(.16, 1, .3, 1) var(--d, 0ms);
        }

        .reveal-on .hero-visual {
            --d: 120ms;
        }

        .reveal-on .section .frame--split {
            --d: 120ms;
        }

        .reveal-on .is-in.is-in.is-in {
            opacity: 1;
            transform: none;
        }

        .reveal-on :is(.hero-visual, .frame--split).is-in>.frame__img {
            transform: none;
        }

        /* reduced motion: no travel, no wipe; the photograph and the words simply arrive */
        @media (prefers-reduced-motion: reduce) {
            html:not([data-motion="off"]) :is(.hero-visual, .hero__text) {
                animation: hero-fade 400ms ease-out both;
            }
        }
    </style>
</head>

<body>
    <!-- type: service (customs brokerage and trucking; site-kind other; photographs from the client's company profile)
     deal: hero split, nav nav-split, close close-type, footer footer-columns, rhythm surface-pairs, buttons tonal,
     mark: the client's logo, palette: brand (stone ground, charcoal ink, the logo's blue), type source-serif
     pictures: the profile's photographs (hero, services, about), the client portal (live HTML); no effects, grounds or loops -->
    <header class="nav nav--split">
        <div class="wrap nav__bar">
            <nav class="nav__links" aria-label="Main">
                <div class="nav__item"><a class="nav__link" href="#services">Services</a></div>
                <div class="nav__item"><a class="nav__link" href="#about">About</a></div>
                <div class="nav__item"><a class="nav__link" href="#portal">Portal</a></div>
                <div class="nav__item"><a class="nav__link" href="#process">Process</a></div>
            </nav>
            <a class="nav__logo" href="#top"><img src="{{ asset('assets/images/astra-logo-64.png') }}" alt="" width="32"
                    height="32">Astra Cargo</a>
            <div class="nav__actions">
                <a class="btn btn--primary" href="{{ $trackUrl }}">{{ $mainAction }}</a>
            </div>
            <button class="nav__toggle" type="button" aria-expanded="false" aria-label="Menu">
                <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
                    <path d="M2 5h14M2 13h14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
            </button>
        </div>
    </header>

    <main id="top">
        <section class="hero hero--split" aria-labelledby="hero-h">
            <div class="wrap">
                <div class="hero-text grid hero__grid">
                    <div class="hero__text span-6">
                        <h1 id="hero-h">Customs broker and trucking in Imus, Cavite</h1>
                        <p class="hero__lead">We clear your imports and exports through customs, prepare the documents, pay the duties and
                            truck the cargo to your door. You follow each step online.</p>
                        <div class="btn-row"><a class="btn btn--primary" href="{{ $trackUrl }}">{{ $mainAction }}</a><a
                                class="btn btn--secondary" href="#services">See our services</a></div>
                        <dl class="hero-facts">
                            <div>
                                <dt>Founded</dt>
                                <dd>2023</dd>
                            </div>
                            <div>
                                <dt>Office</dt>
                                <dd>Buhay na Tubig, Imus</dd>
                            </div>
                            <div>
                                <dt>Call</dt>
                                <dd><a href="tel:+639156266265">0915 626 6265</a></dd>
                            </div>
                        </dl>
                    </div>
                    <div class="hero-visual frame frame--photo span-6 start-7">
                        <img class="frame__img" src="{{ asset('assets/images/landing/ship-aerial.webp') }}"
                            alt="A container ship at sea, seen from above" width="1400" height="932" fetchpriority="high"
                            data-source="client" data-subject="container ship">
                    </div>
                </div>
            </div>
        </section>

        <section class="section" id="services" aria-labelledby="services-h">
            <div class="wrap">
                <div class="grid">
                    <div class="span-6">
                        <div class="section-head">
                            <h2 id="services-h">Customs clearance and trucking. <span class="muted">From the port to your door.</span>
                            </h2>
                        </div>
                        <ul class="rows">
                            <li><span class="rows__name">Customs clearance</span>
                                <p>Every customs formality for your import or export, handled for you.</p><span class="rows__value">Import,
                                    export</span>
                            </li>
                            <li><span class="rows__name">Tariff classification</span>
                                <p>Your goods classified correctly, so you pay the right duties and taxes.</p><span
                                    class="rows__value">Advice</span>
                            </li>
                            <li><span class="rows__name">Import and export documents</span>
                                <p>Bills of lading, invoices and certificates of origin, filed.</p><span class="rows__value">Filing</span>
                            </li>
                            <li><span class="rows__name">Duties and taxes</span>
                                <p>Duty and VAT payments handled, with advice on lowering them.</p><span class="rows__value">Per
                                    entry</span>
                            </li>
                            <li><span class="rows__name">Compliance and trade agreements</span>
                                <p>The rules explained, and trade agreements used to cut duties.</p><span
                                    class="rows__value">Consulting</span>
                            </li>
                            <li><span class="rows__name">Trucking and delivery</span>
                                <p>FCL and LCL, local and long-distance, special cargo.</p><span class="rows__value">Land</span>
                            </li>
                        </ul>
                    </div>
                    <div class="frame frame--photo frame--split span-6 start-7">
                        <img class="frame__img" src="{{ asset('assets/images/landing/container-truck.webp') }}"
                            alt="A truck hauling a red shipping container" width="1168" height="779" loading="lazy"
                            data-source="client" data-subject="container truck">
                    </div>
                </div>
            </div>
        </section>

        <section class="section" id="about" aria-labelledby="about-h">
            <div class="wrap">
                <div class="grid">
                    <div class="span-6">
                        <div class="section-head">
                            <h2 id="about-h">A customs broker since 2023</h2>
                        </div>
                        <p class="t-lead">Astra Cargo Impex Solutions helps businesses through the rules of international trade. Goods
                            clear customs promptly and in line with local and international law, at a fair cost.</p>
                        <ul class="rows about-points">
                            <li><span class="rows__name">Trade law and customs expertise</span></li>
                            <li><span class="rows__name">An online portal for faster clearance</span></li>
                            <li><span class="rows__name">Partner freight forwarders worldwide</span></li>
                            <li><span class="rows__name">Quick replies and clear updates</span></li>
                        </ul>
                        <p><a class="link" href="#contact">Contact our office</a></p>
                    </div>
                    <div class="frame frame--photo frame--split span-6 start-7">
                        <img class="frame__img" src="{{ asset('assets/images/landing/ship-at-sea.webp') }}"
                            alt="A loaded container ship crossing open water" width="1093" height="728" loading="lazy"
                            data-source="client" data-subject="container ship">
                    </div>
                </div>
            </div>
        </section>

        <section class="section ground-surface" id="portal" aria-labelledby="portal-h">
            <div class="wrap">
                <div class="grid">
                    <div class="span-6">
                        <div class="section-head">
                            <h2 id="portal-h">Client portal for your shipments</h2>
                        </div>
                        <p class="t-lead">Sign in to see each shipment's stage, the documents we hold and your bills. Upload a missing
                            invoice or packing list and our team reviews it.</p>
                        <ul class="rows portal-points">
                            <li><span class="rows__name">Stage updates for every shipment</span></li>
                            <li><span class="rows__name">Document checklist and uploads</span></li>
                            <li><span class="rows__name">Bills and payment records</span></li>
                        </ul>
                        <p><a class="link" href="#process">See the 3 steps</a></p>
                    </div>
                    <div class="frame frame--split span-6 start-7" role="img"
                        aria-label="Six shipping documents for shipment AST-2026-0018 in the portal, each checked">
                        <div class="stage stage--fade-br">
                            <div class="at-rise fit" style="--fit-max:440px;--ax:max(24px, 50% - 220px);--ay:56px;--ay-sm:24px">
                                <div class="ui ui-files" data-ui="files" data-ui-still data-reading="Checking {n} of {total}">
                                    <div class="ui-files__status"><span class="ui-dot"></span><span data-files-status>6 documents ·
                                            AST-2026-0018</span></div>
                                    <ul class="ui-files__grid">
                                        <li class="ui-file is-done"><span class="ui-file__icon"><img data-icon="pdf"
                                                    src="{{ asset('assets/taste/icons/pdf.svg') }}" alt=""></span><span
                                                class="ui-file__name">Bill of lading</span><span class="ui-file__kind">PDF</span><span
                                                class="ui-file__bar"><i></i></span><span class="ui-file__check"><svg viewBox="0 0 16 16"
                                                    aria-hidden="true">
                                                    <path d="m4.5 8.2 2.3 2.3 4.7-5" fill="none" stroke="currentColor"
                                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                                </svg></span></li>
                                        <li class="ui-file is-done"><span class="ui-file__icon"><img data-icon="pdf"
                                                    src="{{ asset('assets/taste/icons/pdf.svg') }}" alt=""></span><span
                                                class="ui-file__name">Commercial invoice</span><span class="ui-file__kind">PDF</span><span
                                                class="ui-file__bar"><i></i></span><span class="ui-file__check"><svg viewBox="0 0 16 16"
                                                    aria-hidden="true">
                                                    <path d="m4.5 8.2 2.3 2.3 4.7-5" fill="none" stroke="currentColor"
                                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                                </svg></span></li>
                                        <li class="ui-file is-done"><span class="ui-file__icon"><img data-icon="xlsx"
                                                    src="{{ asset('assets/taste/icons/xlsx.svg') }}" alt=""></span><span
                                                class="ui-file__name">Packing list</span><span class="ui-file__kind">Excel</span><span
                                                class="ui-file__bar"><i></i></span><span class="ui-file__check"><svg viewBox="0 0 16 16"
                                                    aria-hidden="true">
                                                    <path d="m4.5 8.2 2.3 2.3 4.7-5" fill="none" stroke="currentColor"
                                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                                </svg></span></li>
                                        <li class="ui-file is-done"><span class="ui-file__icon"><img data-icon="pdf"
                                                    src="{{ asset('assets/taste/icons/pdf.svg') }}" alt=""></span><span
                                                class="ui-file__name">Import entry</span><span class="ui-file__kind">PDF</span><span
                                                class="ui-file__bar"><i></i></span><span class="ui-file__check"><svg viewBox="0 0 16 16"
                                                    aria-hidden="true">
                                                    <path d="m4.5 8.2 2.3 2.3 4.7-5" fill="none" stroke="currentColor"
                                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                                </svg></span></li>
                                        <li class="ui-file is-done"><span class="ui-file__icon"><img data-icon="pdf"
                                                    src="{{ asset('assets/taste/icons/pdf.svg') }}" alt=""></span><span
                                                class="ui-file__name">Assessment notice</span><span class="ui-file__kind">PDF</span><span
                                                class="ui-file__bar"><i></i></span><span class="ui-file__check"><svg viewBox="0 0 16 16"
                                                    aria-hidden="true">
                                                    <path d="m4.5 8.2 2.3 2.3 4.7-5" fill="none" stroke="currentColor"
                                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                                </svg></span></li>
                                        <li class="ui-file is-done"><span class="ui-file__icon"><img data-icon="pdf"
                                                    src="{{ asset('assets/taste/icons/pdf.svg') }}" alt=""></span><span
                                                class="ui-file__name">Duties receipt</span><span class="ui-file__kind">PDF</span><span
                                                class="ui-file__bar"><i></i></span><span class="ui-file__check"><svg viewBox="0 0 16 16"
                                                    aria-hidden="true">
                                                    <path d="m4.5 8.2 2.3 2.3 4.7-5" fill="none" stroke="currentColor"
                                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                                </svg></span></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section ground-surface" id="process" style="--hm: 2" aria-labelledby="process-h">
            <div class="wrap">
                <div class="section-head">
                    <h2 id="process-h">A shipment in 3 steps</h2>
                </div>
                <div class="grid">
                    <article class="card card--row card--open span-4">
                        <span class="step-num">1</span>
                        <h3>Send your shipping documents</h3>
                        <p>Upload the B/L, invoice and packing list, or send the whole packet for a new shipment.</p>
                        <div class="panel">
                            <div class="stage stage--fade-b">
                                <div class="at-rise fit" style="--fit-max:380px;--ax:max(16px, 50% - 190px);--ay:32px;--ay-sm:24px">
                                    <div class="ui ui-notify" data-ui="notify" data-ui-still>
                                        <ul class="ui-notify__list" data-notify-list>
                                            <li class="ui-note"><span class="ui-note__ico"><svg class="ui-icon" viewBox="0 0 16 16">
                                                        <path d="M4 2.5h5l3 3v8H4z" />
                                                        <path d="M9 2.5v3h3" />
                                                    </svg></span><span class="ui-note__text"><span class="ui-note__title">Packing list
                                                        due<time>now</time></span><span class="ui-note__body">AST-2026-0017, due Nov
                                                        12</span></span></li>
                                            <li class="ui-note"><span class="ui-note__ico"><svg class="ui-icon" viewBox="0 0 16 16">
                                                        <path d="m3.5 8.4 2.8 2.8 6.2-6.4" />
                                                    </svg></span><span class="ui-note__text"><span class="ui-note__title">Invoice
                                                        accepted<time>1h</time></span><span class="ui-note__body">AST-2026-0017, checked by
                                                        our team</span></span></li>
                                            <li class="ui-note"><span class="ui-note__ico"><svg class="ui-icon" viewBox="0 0 16 16">
                                                        <path d="M8 11V3M4.8 6.2 8 3l3.2 3.2" />
                                                        <path d="M3 13h10" />
                                                    </svg></span><span class="ui-note__text"><span class="ui-note__title">B/L
                                                        uploaded<time>2h</time></span><span class="ui-note__body">AST-2026-0017, 2.6 MB
                                                        PDF</span></span></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                    <article class="card card--row card--open span-4">
                        <span class="step-num">2</span>
                        <h3>We clear it with customs</h3>
                        <p>We lodge the entry, check the assessment and pay the duties on your behalf.</p>
                        <div class="panel">
                            <div class="stage stage--fade-b">
                                <div class="at-rise fit" style="--fit-max:340px;--ax:max(16px, 50% - 170px);--ay:32px;--ay-sm:24px">
                                    <div class="ui ui-panel ui-checks" data-ui="checklist" data-ui-still>
                                        <div class="ui-checks__head">
                                            <div class="ui-title">Customs, AST-2026-0018</div><span class="ui-spacer"></span>
                                            <div class="ui-meta" data-checks-count>3 of 4</div>
                                        </div>
                                        <div class="ui-checks__bar"><i data-checks-bar style="--k:0.75"></i></div>
                                        <ul class="ui-checks__list">
                                            <li class="ui-check is-done"><span class="ui-check__box"><svg viewBox="0 0 16 16">
                                                        <path d="m4.5 8.2 2.3 2.3 4.7-5" />
                                                    </svg></span><span class="ui-check__label">Import entry lodged</span></li>
                                            <li class="ui-check is-done"><span class="ui-check__box"><svg viewBox="0 0 16 16">
                                                        <path d="m4.5 8.2 2.3 2.3 4.7-5" />
                                                    </svg></span><span class="ui-check__label">Assessment notice checked</span></li>
                                            <li class="ui-check is-done"><span class="ui-check__box"><svg viewBox="0 0 16 16">
                                                        <path d="m4.5 8.2 2.3 2.3 4.7-5" />
                                                    </svg></span><span class="ui-check__label">Duties and taxes paid</span></li>
                                            <li class="ui-check"><span class="ui-check__box"><svg viewBox="0 0 16 16">
                                                        <path d="m4.5 8.2 2.3 2.3 4.7-5" />
                                                    </svg></span><span class="ui-check__label">Released from the port</span></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                    <article class="card card--row card--open span-4">
                        <span class="step-num">3</span>
                        <h3>We truck it to your door</h3>
                        <p>A booked truck, a named driver and your receiving contact on the delivery day.</p>
                        <div class="panel">
                            <div class="stage stage--fade-b">
                                <div class="at-rise fit" style="--fit-max:340px;--ax:max(16px, 50% - 170px);--ay:32px;--ay-sm:24px">
                                    <div class="ui ui-panel ui-delivery">
                                        <div class="ui-head">
                                            <div class="ui-title">AST-2026-0014</div><span class="ui-spacer"></span><span
                                                class="ui-chip">Delivered</span>
                                        </div>
                                        <ul class="ui-delivery__rows">
                                            <li><span>Pickup</span><span>Manila port, 8:00</span></li>
                                            <li><span>Trucker</span><span>Booked</span></li>
                                            <li><span>Driver</span><span>Assigned</span></li>
                                            <li><span>Received by</span><span>Your warehouse</span></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </div>
        </section>

    </main>

    <footer class="footer closing g2m" id="contact" aria-labelledby="close-h">
        <div class="wrap">
            <div class="closing__cta close--type">
                <div class="section-head">
                    <h2 id="close-h">Track your next shipment online</h2>
                    <p>Sign in with your client account, or call us on <a href="tel:+639156266265">0915 626 6265</a>.</p>
                </div>
                <a class="btn btn--primary btn--xl" href="{{ $trackUrl }}">{{ $mainAction }}</a>
            </div>
            <div class="closing__links">
                <div class="grid">
                    <div class="span-4 stack-4">
                        <a class="nav__logo" href="#top"><img src="{{ asset('assets/images/astra-logo-64.png') }}" alt=""
                                width="32" height="32">Astra Cargo</a>
                        <p class="footer__lede">B39 L12 Hacienda Lajoya, Buhay na Tubig, Imus, Cavite</p>
                    </div>
                    <div class="span-2 footer__col">
                        <h4>Services</h4>
                        <ul>
                            <li><a href="#services">Customs clearance</a></li>
                            <li><a href="#services">Documents</a></li>
                            <li><a href="#services">Trucking</a></li>
                        </ul>
                    </div>
                    <div class="span-2 footer__col">
                        <h4>Company</h4>
                        <ul>
                            <li><a href="#about">About us</a></li>
                            <li><a href="#portal">Client portal</a></li>
                            <li><a href="#process">Three steps</a></li>
                        </ul>
                    </div>
                    <div class="span-4 footer__col">
                        <h4>Contact</h4>
                        <ul>
                            <li><a href="tel:+639156266265">0915 626 6265</a></li>
                            <li><a href="mailto:astracargoacis@gmail.com">astracargoacis@gmail.com</a></li>
                            <li><a href="https://www.openstreetmap.org/search?query=Hacienda%20Lajoya%20Buhay%20na%20Tubig%20Imus%20Cavite"
                                    rel="noopener">Get directions</a></li>
                        </ul>
                    </div>
                </div>
                <div class="footer__legal">
                    <span>&copy; {{ date('Y') }} Astra Cargo Impex Solutions OPC</span>
                    <div class="socials"></div>
                </div>
            </div>
        </div>
    </footer>

    <script type="module" src="{{ asset('assets/taste/system.js') }}"></script>
    <script type="module" src="{{ asset('assets/taste/motion/motion.js') }}"></script>
    <script type="module" src="{{ asset('assets/taste/layout/layout.js') }}"></script>
    <script type="module">
        // scroll reveal: each target gets .is-in the first time 15% of it is on screen, then is left alone.
        // Siblings revealed together stagger 70ms apart (rows) or 140ms (cards, footer columns), capped.
        const html = document.documentElement;
        if (html.classList.contains('reveal-on')) {
            window.__reveal = true;
            const sel =
                '.section .section-head, .section .t-lead, .section .span-6 > p, .rows > li, .section .card--open, .closing__cta, .closing__links .grid > *, .footer__legal';
            const els = [...document.querySelectorAll(sel)];
            els.forEach(el => {
                const sibs = [...el.parentElement.children].filter(s => els.includes(s));
                const i = sibs.indexOf(el);
                const step = el.matches('.rows > li') ? 70 : 140;
                if (i > 0) el.style.setProperty('--d', Math.min(i * step, 560) + 'ms');
            });
            const io = new IntersectionObserver(entries => entries.forEach(e => {
                if (!e.isIntersecting) return;
                e.target.classList.add('is-in');
                io.unobserve(e.target);
            }), {
                threshold: 0.15,
                rootMargin: '0px 0px -8% 0px'
            });
            els.forEach(el => io.observe(el));
            // the pictures (the hero's included) pop in once too, on the same rule
            document.querySelectorAll('.hero-visual, .section .frame--split').forEach(f => io.observe(f));
        }
    </script>
    <script type="module">
        // the portal and the three steps show the real work: as each product picture comes into view, its
        // fragment plays its own sequence once (documents read and checked, notes arriving, checks ticking)
        // and then rests on the finished picture it showed before. ui.js holds them still otherwise, plays
        // them on hover and plays nothing under reduced motion.
        const html = document.documentElement;
        if (html.dataset.motion !== 'off' && !matchMedia('(prefers-reduced-motion: reduce)').matches && 'IntersectionObserver' in window) {
            const ready = () => new Promise(res => {
                if (html.dataset.ready === '1') return res();
                const mo = new MutationObserver(() => {
                    if (html.dataset.ready === '1') {
                        mo.disconnect();
                        res();
                    }
                });
                mo.observe(html, {
                    attributes: true,
                    attributeFilter: ['data-ready']
                });
                setTimeout(() => {
                    mo.disconnect();
                    res();
                }, 6000);
            });
            ready().then(() => {
                const frags = [...document.querySelectorAll('#portal [data-ui], #process [data-ui]')];
                const io = new IntersectionObserver(entries => entries.forEach(e => {
                    if (!e.isIntersecting) return;
                    io.unobserve(e.target);
                    // the three steps play in their order, 240ms apart, as one row
                    const i = Number(e.target.dataset.mStep || 0);
                    setTimeout(() => {
                        try {
                            e.target.__ui?.restart?.();
                        } catch (err) {
                            console.warn(err);
                        }
                    }, i * 240);
                }), {
                    threshold: 0.55
                });
                document.querySelectorAll('#process [data-ui]').forEach((el, i) => {
                    el.dataset.mStep = i;
                });
                frags.forEach(el => io.observe(el));
            });
        }
    </script>
</body>

</html>
