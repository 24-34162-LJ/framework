/*
 * FILE PURPOSE
 * File   : public/assets/js/client_documents.js
 * Feature: Documents
 * Purpose: Document uploads (photo to PDF), Gemini document check, document and shipment requests.
 */

/* Client document uploads, photo → PDF capture, Gemini document checks, staff document requests,
   and client shipment requests. Used on the client shipment page, the staff shipment page and the
   client Shipment Requests page. */
document.addEventListener("DOMContentLoaded", () => {
    const $ = id => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf"]')?.content || "";
    const MAX_DOC = 10 * 1024 * 1024;
    const MAX_PACKET = 25 * 1024 * 1024;
    const mb = size => `${(size / 1024 / 1024).toFixed(1)} MB`;

    async function postJson(url, body = new FormData()) {
        const response = await fetch(url, { method: "POST", body, credentials: "same-origin", headers: { "X-CSRF-Token": csrf, Accept: "application/json" } });
        let data = {};
        try { data = await response.json(); } catch (_) { throw new Error("The server returned an unreadable response. Please try again."); }
        if (!response.ok || data.ok === false) throw new Error(data.error || "Request failed.");
        return data;
    }

    /* XHR so the upload can show progress. */
    function upload(url, formData, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open("POST", url);
            xhr.setRequestHeader("X-CSRF-Token", csrf);
            xhr.setRequestHeader("Accept", "application/json");
            xhr.upload.addEventListener("progress", e => { if (e.lengthComputable) onProgress(Math.round(e.loaded / e.total * 100)); });
            xhr.addEventListener("load", () => {
                let data;
                try { data = JSON.parse(xhr.responseText); } catch (_) { reject(new Error("The server returned an unreadable response. Please try again.")); return; }
                if (xhr.status < 200 || xhr.status >= 300 || data.ok === false) reject(Object.assign(new Error(data.error || "Upload failed."), { duplicate: !!data.duplicate }));
                else resolve(data);
            });
            xhr.addEventListener("error", () => reject(new Error("A network error interrupted the upload.")));
            xhr.send(formData);
        });
    }

    /* Photos → one PDF (A4 pages, images downscaled so the PDF stays small). */
    async function photosToPdf(files) {
        const jsPDF = window.jspdf?.jsPDF;
        if (!jsPDF) throw new Error("The photo converter did not load. Check your connection and try again.");
        const pdf = new jsPDF({ unit: "mm", format: "a4", compress: true });
        for (let i = 0; i < files.length; i++) {
            const bitmap = await createImageBitmap(files[i]);
            const scale = Math.min(1, 1800 / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement("canvas");
            canvas.width = Math.round(bitmap.width * scale); canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext("2d").drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            const data = canvas.toDataURL("image/jpeg", 0.82);
            const pageW = 210, pageH = 297, margin = 8;
            const ratio = Math.min((pageW - 2 * margin) / canvas.width, (pageH - 2 * margin) / canvas.height);
            const w = canvas.width * ratio, h = canvas.height * ratio;
            if (i > 0) pdf.addPage();
            pdf.addImage(data, "JPEG", (pageW - w) / 2, (pageH - h) / 2, w, h);
        }
        const stamp = new Date().toISOString().slice(0, 16).replace(/[-:T]/g, "");
        return new File([pdf.output("blob")], `photos_${stamp}.pdf`, { type: "application/pdf" });
    }

    /* Runs the Gemini document check and returns a short message (never throws). */
    async function runCheck(url) {
        try {
            const data = await postJson(url);
            const check = data.check || {};
            if (check.status === "warning") return { tone: "warn", text: `Please double-check this file: ${(check.issues || []).slice(0, 2).join(" ")}` };
            if (check.status === "ok") return { tone: "ok", text: `Auto-check passed: looks like ${check.detected_type || "the right document"}.` };
            return { tone: "muted", text: check.summary || "Auto-check finished." };
        } catch (error) {
            return { tone: "muted", text: `Auto-check could not run (${error.message}). Our team will still review your file.` };
        }
    }

    /* ------------------------------------------------ client upload dialog */
    const dialog = $("clientUploadDialog");
    if (dialog) {
        const form = $("clientUploadForm"), type = $("cuType"), fileInput = $("cuFile"), camera = $("cuCamera");
        const chosen = $("cuChosen"), message = $("cuMessage"), submit = $("cuSubmit"), progress = $("cuProgress"), bar = $("cuProgressBar");
        const photosBox = $("cuPhotos"), thumbs = $("cuThumbs"), photoCount = $("cuPhotoCount"), replaces = $("cuReplaces");
        let file = null, photos = [], busy = false;

        const say = (text, tone = "error") => { message.hidden = !text; message.textContent = text || ""; message.dataset.tone = tone; };
        const refresh = () => {
            chosen.hidden = !file;
            if (file) chosen.textContent = `Selected: ${file.name} · ${mb(file.size)}`;
            photosBox.hidden = photos.length === 0;
            photoCount.textContent = `${photos.length} photo${photos.length === 1 ? "" : "s"}`;
            submit.disabled = busy || (!file && photos.length === 0);
        };
        const pickFile = f => {
            if (!f) return;
            const ext = f.name.split(".").pop().toLowerCase();
            if (!["pdf", "jpg", "jpeg", "png", "xls", "xlsx"].includes(ext)) { say("This file type is not supported. Use PDF, JPG, PNG, XLS or XLSX."); return; }
            if (!f.size || f.size > MAX_DOC) { say("The file must be between 1 byte and 10 MB."); return; }
            file = f; photos = []; thumbs.replaceChildren(); say(""); refresh();
        };
        document.querySelectorAll("[data-client-upload]").forEach(button => button.addEventListener("click", () => {
            form.reset(); file = null; photos = []; thumbs.replaceChildren(); say(""); progress.hidden = true; busy = false;
            const preset = button.dataset.clientUpload;
            if (preset) [...type.options].forEach(o => { if (o.value === preset) type.value = preset; });
            replaces.value = button.dataset.replaces || "";
            $("clientUploadTitle").textContent = button.dataset.replaces ? "Replace your upload" : "Upload a document";
            refresh(); dialog.showModal();
        }));
        dialog.querySelectorAll("[data-cu-close]").forEach(b => b.addEventListener("click", () => { if (!busy) dialog.close(); }));
        fileInput.addEventListener("change", () => pickFile(fileInput.files[0]));
        const drop = $("cuDrop");
        ["dragover", "dragenter"].forEach(n => drop.addEventListener(n, e => { e.preventDefault(); drop.classList.add("is-over"); }));
        ["dragleave", "drop"].forEach(n => drop.addEventListener(n, () => drop.classList.remove("is-over")));
        drop.addEventListener("drop", e => { e.preventDefault(); pickFile(e.dataTransfer.files[0]); });
        camera.addEventListener("change", () => {
            const added = [...camera.files].filter(f => f.type.startsWith("image/"));
            camera.value = "";
            if (!added.length) return;
            file = null; fileInput.value = ""; photos.push(...added);
            added.forEach(f => { const img = document.createElement("img"); img.src = URL.createObjectURL(f); img.alt = ""; thumbs.append(img); });
            say(""); refresh();
        });
        $("cuPhotosClear").addEventListener("click", () => { photos = []; thumbs.replaceChildren(); refresh(); });

        form.addEventListener("submit", async event => {
            event.preventDefault();
            if (busy) return;
            busy = true; refresh(); say("");
            try {
                let toSend = file;
                if (!toSend && photos.length) {
                    say("Making a PDF from your photos…", "info");
                    toSend = await photosToPdf(photos);
                    if (toSend.size > MAX_DOC) throw new Error(`The PDF from your photos is ${mb(toSend.size)}. Use fewer photos (10 MB maximum).`);
                }
                const data = new FormData(form);
                data.append("file", toSend);
                progress.hidden = false; bar.style.width = "0%"; progress.setAttribute("aria-valuenow", "0");
                say("Uploading…", "info");
                const result = await upload(dialog.dataset.endpoint, data, p => { bar.style.width = `${p}%`; progress.setAttribute("aria-valuenow", String(Math.round(p))); });
                if (result.check_url) {
                    say(`${result.message} Checking the document automatically… (up to a minute)`, "info");
                    const check = await runCheck(result.check_url);
                    say(`${result.message} ${check.text}`, check.tone === "warn" ? "warn" : "ok");
                } else say(result.message, "ok");
                submit.textContent = "Done";
                setTimeout(() => window.location.reload(), 2200);
            } catch (error) {
                say(error.message || "Upload failed.");
                busy = false; refresh(); progress.hidden = true;
            }
        });
    }

    /* Client page: run checks that never ran (for example the client left before it finished). */
    (async () => {
        for (const node of document.querySelectorAll("[data-ai-pending]")) {
            node.textContent = "Checking the document automatically…";
            const check = await runCheck(node.dataset.aiPending);
            node.textContent = check.text;
            node.className = check.tone === "warn" ? "cd-ai-warn" : check.tone === "ok" ? "cd-ai-ok" : "cd-muted";
        }
    })();

    /* ------------------------------------------------ staff: auto-check buttons */
    document.querySelectorAll("[data-ai-check]").forEach(button => button.addEventListener("click", async () => {
        button.disabled = true; button.textContent = "Checking… (up to a minute)";
        const check = await runCheck(button.dataset.aiCheck);
        button.textContent = check.text;
        setTimeout(() => window.location.reload(), 1500);
    }));

    /* ------------------------------------------------ staff: request a document */
    const requestDialog = $("requestDocDialog");
    if (requestDialog) {
        document.querySelectorAll("[data-request-doc]").forEach(button => button.addEventListener("click", () => {
            $("requestDocType").value = button.dataset.requestDoc;
            requestDialog.showModal();
        }));
        requestDialog.querySelectorAll("[data-close-request]").forEach(b => b.addEventListener("click", () => requestDialog.close()));
    }

    /* ------------------------------------------------ client: new shipment request */
    const srForm = $("srForm");
    if (srForm) {
        const input = $("srFile"), status = $("srStatus"), submit = $("srSubmit"), bar = $("srProgressBar"), progress = $("srProgress");
        let busy = false;
        const say = (text, tone = "error") => { status.hidden = !text; status.textContent = text || ""; status.dataset.tone = tone; };
        input.addEventListener("change", () => {
            const f = input.files[0];
            say("");
            submit.disabled = !f;
            if (!f) return;
            if (!/\.pdf$/i.test(f.name)) { say("Only PDF files are accepted."); submit.disabled = true; }
            else if (f.size > MAX_PACKET) { say(`The file is ${mb(f.size)}. The limit is 25 MB.`); submit.disabled = true; }
            else $("srChosen").textContent = `${f.name} · ${mb(f.size)}`;
        });
        srForm.addEventListener("submit", async event => {
            event.preventDefault();
            if (busy || !input.files[0]) return;
            busy = true; submit.disabled = true; progress.hidden = false; bar.style.width = "0%"; progress.setAttribute("aria-valuenow", "0");
            try {
                say("Uploading your documents…", "info");
                const result = await upload(srForm.dataset.endpoint, new FormData(srForm), p => { bar.style.width = `${p}%`; progress.setAttribute("aria-valuenow", String(Math.round(p))); });
                say(`${result.reference} sent. Reading your documents so our team can start faster… (up to a minute)`, "info");
                try {
                    const read = await postJson(result.extract_url);
                    const s = read.summary || {};
                    say(`${result.reference} sent and read: B/L ${s.bl_awb_no || "not found"} · ${s.route} · ${s.containers} container(s). Our team will confirm it and create your shipment.`, "ok");
                } catch (error) {
                    say(`${result.reference} sent. Automatic reading did not finish (${error.message}); our team will read it manually.`, "warn");
                }
                setTimeout(() => window.location.reload(), 3500);
            } catch (error) {
                say(error.message || "Upload failed.");
                busy = false; submit.disabled = false; progress.hidden = true;
            }
        });
    }

    /* Staff: run auto-read for a request from the Shipment Requests list. */
    document.querySelectorAll("[data-sr-extract]").forEach(button => button.addEventListener("click", async () => {
        /* The result goes to a live region next to the button so screen readers announce it.
           Success refreshes the list; a failure stays on screen until staff act on it. */
        let note = button.parentElement.querySelector(".sr-extract-note");
        if (!note) {
            note = document.createElement("span");
            note.className = "sr-extract-note"; note.setAttribute("role", "status");
            button.after(note);
        }
        button.disabled = true; button.setAttribute("aria-busy", "true");
        note.dataset.tone = "info"; note.textContent = "Reading the documents… this can take up to a minute.";
        try {
            await postJson(button.dataset.srExtract);
            note.dataset.tone = "ok"; note.textContent = "Auto-read finished. Refreshing the list…";
            setTimeout(() => window.location.reload(), 1500);
        } catch (error) {
            note.dataset.tone = "error"; note.textContent = `Auto-read failed: ${error.message}`;
            button.disabled = false; button.removeAttribute("aria-busy"); button.textContent = "Try auto-read again";
        }
    }));
});

/* Staff: opening "Decline" puts the cursor straight into the reason field. */
document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("details.cd-reject").forEach(details => details.addEventListener("toggle", () => {
        if (details.open) details.querySelector("input[name=staff_note]")?.focus();
    }));
});
