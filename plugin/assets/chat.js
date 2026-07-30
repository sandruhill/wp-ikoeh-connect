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

    sendBtn.addEventListener("click", sendMessage);
    inputEl.addEventListener("keydown", function (e) {
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    renderHistory(window.ikoehChat.history || []);
})();
