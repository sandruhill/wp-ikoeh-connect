(function () {
    "use strict";

    var POLL_INTERVAL_MS = 5000;
    var restUrl = window.ikoehGutenbergQueue.restUrl;
    var nonce = window.ikoehGutenbergQueue.nonce;

    function apiFetch(path, options) {
        options = options || {};
        options.headers = Object.assign({ "X-WP-Nonce": nonce, "Content-Type": "application/json" }, options.headers || {});
        return fetch(restUrl + path, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    throw new Error(body.message || "Request failed");
                }
                return body;
            });
        });
    }

    function setStatus(text) {
        var el = document.getElementById("ikoeh-gutenberg-queue-status");
        if (el) {
            el.textContent = text;
        }
    }

    function collectBlockNames(specs) {
        var names = [];
        specs.forEach(function (spec) {
            names.push(spec.name);
            if (spec.innerBlocks && spec.innerBlocks.length) {
                names = names.concat(collectBlockNames(spec.innerBlocks));
            }
        });
        return names;
    }

    function specToBlock(spec) {
        var innerBlocks = (spec.innerBlocks || []).map(specToBlock);
        return wp.blocks.createBlock(spec.name, spec.attributes || {}, innerBlocks);
    }

    function validateAndSerialize(blockSpecs) {
        // wp.blocks.createBlock() internally looks up the block type and
        // throws/dereferences on an unregistered name instead of failing
        // gracefully -- so every name (recursively, through innerBlocks)
        // must be checked with getBlockType() BEFORE any createBlock() call,
        // never after.
        var unknownNames = collectBlockNames(blockSpecs).filter(function (name) {
            return !wp.blocks.getBlockType(name);
        });
        if (unknownNames.length > 0) {
            return {
                content: "",
                validations: unknownNames.map(function (name) {
                    return { isValid: false, message: "Unknown block type: " + name };
                }),
            };
        }

        var blocks = blockSpecs.map(specToBlock);
        var content = wp.blocks.serialize(blocks);
        return { content: content, validations: blocks.map(function () { return { isValid: true }; }) };
    }

    function processItem(batchId, leaseOwner) {
        return apiFetch("/gutenberg-claim-item", {
            method: "POST",
            body: JSON.stringify({ batch_id: batchId, lease_owner: leaseOwner }),
        }).then(function (result) {
            // claim_next_item() can return {done:false, batch:...} with no
            // "item" key (e.g. another item in the same batch is still
            // RUNNING under a still-valid lease) -- treat that the same as
            // done for this tick rather than crashing on result.item.block_spec.
            if (result.done || !result.item) {
                return result;
            }
            var result2 = validateAndSerialize(result.item.block_spec);
            return apiFetch("/gutenberg-complete-item", {
                method: "POST",
                body: JSON.stringify({
                    item_id: result.item.item_id,
                    lease_owner: leaseOwner,
                    content: result2.content,
                    validations: result2.validations,
                }),
            }).then(function (completed) {
                if (completed.done) {
                    return completed;
                }
                return processItem(batchId, leaseOwner);
            });
        });
    }

    function processBatch(batch) {
        setStatus("Processando lote #" + batch.batch_id + ": " + batch.label);
        return apiFetch("/gutenberg-claim-batch?id=" + batch.batch_id, { method: "POST" }).then(function (claimed) {
            return processItem(batch.batch_id, claimed.lease_owner);
        });
    }

    function tick() {
        apiFetch("/gutenberg-heartbeat", { method: "POST" })
            .then(function () {
                return apiFetch("/gutenberg-batches?status=ready");
            })
            .then(function (batches) {
                if (batches.length === 0) {
                    setStatus("Nenhuma mudanca pendente. Aguardando...");
                    return;
                }
                return processBatch(batches[0]);
            })
            .catch(function (error) {
                setStatus("Erro: " + error.message);
            })
            .finally(function () {
                setTimeout(tick, POLL_INTERVAL_MS);
            });
    }

    tick();
})();
