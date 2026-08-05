(function () {
    "use strict";

    var messagesEl = document.getElementById("ikoeh-chat-messages");
    var inputEl = document.getElementById("ikoeh-chat-input");
    var sendBtn = document.getElementById("ikoeh-chat-send");
    var statusEl = document.getElementById("ikoeh-chat-status");

    function renderHistory(history) {
        messagesEl.innerHTML = "";
        history.forEach(function (entry) {
            var row = document.createElement("div");
            row.style.marginBottom = "10px";
            row.style.textAlign = entry.role === "user" ? "right" : "left";

            var bubble = document.createElement("span");
            bubble.style.display = "inline-block";
            bubble.style.padding = "8px 12px";
            bubble.style.borderRadius = "12px";
            bubble.style.background = entry.role === "user" ? "#2271b1" : "#f0f0f1";
            bubble.style.color = entry.role === "user" ? "#fff" : "#1d2327";
            bubble.textContent = entry.content;

            row.appendChild(bubble);
            messagesEl.appendChild(row);
        });
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function sendMessage() {
        var message = inputEl.value.trim();
        if (!message) {
            return;
        }

        inputEl.value = "";
        sendBtn.disabled = true;
        statusEl.textContent = "Enviando...";

        var data = new URLSearchParams();
        data.append("action", "ikoeh_chat_send");
        data.append("nonce", window.ikoehChat.nonce);
        data.append("message", message);

        fetch(window.ikoehChat.ajaxUrl, { method: "POST", body: data })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (json.success) {
                    renderHistory(json.data.history);
                    statusEl.textContent = "";
                    pollCloneStatus();
                } else {
                    statusEl.textContent = "Erro: " + (json.data && json.data.message ? json.data.message : "falha desconhecida");
                }
            })
            .catch(function () {
                statusEl.textContent = "Erro: falha ao conectar.";
            })
            .finally(function () {
                sendBtn.disabled = false;
            });
    }

    var POLL_INTERVAL_MS = 5000;
    var jobStatusEl = document.createElement("div");
    jobStatusEl.id = "ikoeh-chat-job-status";
    jobStatusEl.style.marginBottom = "10px";
    jobStatusEl.style.fontSize = "13px";
    jobStatusEl.style.color = "#646970";
    messagesEl.parentNode.insertBefore(jobStatusEl, messagesEl);

    var TERMINAL_STATUSES = ["done", "partial", "failed"];
    var STEP_LABELS = {
        queued: "Na fila...",
        fetching: "Analisando o site de referencia...",
        generating: "Gerando a pagina...",
        publishing: "Publicando...",
        comparing: "Comparando com a referencia...",
        refining: "Ajustando...",
        done: "Concluido.",
        partial: "Concluido parcialmente, revise manualmente.",
        failed: "Falhou."
    };

    function pollCloneStatus() {
        var data = new URLSearchParams();
        data.append("action", "ikoeh_chat_clone_status");
        data.append("nonce", window.ikoehChat.nonce);

        fetch(window.ikoehChat.ajaxUrl, { method: "POST", body: data })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (!json.success || !json.data.job) {
                    jobStatusEl.textContent = "";
                    return;
                }

                var job = json.data.job;
                var label = STEP_LABELS[job.status] || job.status;
                jobStatusEl.textContent = "Clonagem (" + job.iteration + "/" + job.max_iterations + "): " + label;

                if (job.target_post_id && TERMINAL_STATUSES.indexOf(job.status) !== -1) {
                    renderUndoButton(job.target_post_id);
                }

                if (TERMINAL_STATUSES.indexOf(job.status) === -1) {
                    setTimeout(pollCloneStatus, POLL_INTERVAL_MS);
                } else {
                    renderHistory(json.data.history || []);
                }
            })
            .catch(function () {
                setTimeout(pollCloneStatus, POLL_INTERVAL_MS);
            });
    }

    function renderUndoButton(postId) {
        var existing = document.getElementById("ikoeh-chat-undo-btn");
        if (existing) {
            existing.remove();
        }

        var btn = document.createElement("button");
        btn.id = "ikoeh-chat-undo-btn";
        btn.type = "button";
        btn.className = "button";
        btn.textContent = "Desfazer ultima alteracao";
        btn.style.marginBottom = "10px";
        btn.addEventListener("click", function () {
            btn.disabled = true;
            var data = new URLSearchParams();
            data.append("action", "ikoeh_chat_undo");
            data.append("nonce", window.ikoehChat.nonce);
            data.append("post_id", postId);

            fetch(window.ikoehChat.ajaxUrl, { method: "POST", body: data })
                .then(function (response) { return response.json(); })
                .then(function (json) {
                    statusEl.textContent = json.success ? "Alteracao desfeita." : "Erro: " + json.data.message;
                })
                .finally(function () {
                    btn.disabled = false;
                });
        });

        jobStatusEl.parentNode.insertBefore(btn, jobStatusEl.nextSibling);
    }

    sendBtn.addEventListener("click", sendMessage);
    inputEl.addEventListener("keydown", function (e) {
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    renderHistory(window.ikoehChat.history || []);
    pollCloneStatus();
})();
