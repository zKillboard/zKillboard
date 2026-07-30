(function () {
	"use strict";

	const match = window.location.pathname.match(/^\/(character|corporation|alliance)\/([1-9]\d{0,10})(?:\/|$)/);
	if (!match) return;

	const type = match[1];
	const id = Number(match[2]);
	const root = document.getElementById("zkb-esi-entity-fallback");
	if (!root || !Number.isSafeInteger(id) || id <= 0) return;
	if (!window.fetch) {
		showStandard404();
		return;
	}

	const config = {
		character: {
			label: "Character",
			esi: "characters",
			image: "https://images.evetech.net/characters/" + id + "/portrait?size=128",
			imageAlt: "Character portrait",
			imageClass: "img-rounded",
			zkbPath: "/character/" + id + "/"
		},
		corporation: {
			label: "Corporation",
			esi: "corporations",
			image: "https://images.evetech.net/corporations/" + id + "/logo?size=128",
			imageAlt: "Corporation logo",
			imageClass: "img-rounded",
			zkbPath: "/corporation/" + id + "/"
		},
		alliance: {
			label: "Alliance",
			esi: "alliances",
			image: "https://images.evetech.net/alliances/" + id + "/logo?size=128",
			imageAlt: "Alliance logo",
			imageClass: "img-rounded",
			zkbPath: "/alliance/" + id + "/"
		}
	}[type];
	if (!config) return;

	fetchEntity(config.esi, id).then(async function (entity) {
		if (!entity || !entity.name) {
			showStandard404();
			return;
		}

		const related = await fetchRelated(type, entity);
		renderEntity(entity, related);
	}).catch(showStandard404);

	function fetchEntity(endpoint, entityID) {
		return fetch("https://esi.evetech.net/" + endpoint + "/" + entityID + "/?datasource=tranquility", {
			headers: { "Accept": "application/json", "X-User-Agent": "zkillboard.com entity fallback" }
		}).then(function (response) {
			if (response.status === 404) return null;
			if (!response.ok) throw new Error("ESI returned " + response.status);
			return response.json();
		});
	}

	async function fetchRelated(entityType, entity) {
		const related = {};
		const jobs = [];

		if (entityType === "character") {
			if (entity.corporation_id) {
				jobs.push(fetchEntity("corporations", entity.corporation_id).then(function (corp) {
					related.corporation = corp;
				}).catch(function () {}));
			}
			if (entity.alliance_id) {
				jobs.push(fetchEntity("alliances", entity.alliance_id).then(function (alliance) {
					related.alliance = alliance;
				}).catch(function () {}));
			}
		}

		if (entityType === "corporation") {
			if (entity.ceo_id) {
				jobs.push(fetchEntity("characters", entity.ceo_id).then(function (ceo) {
					related.ceo = ceo;
				}).catch(function () {}));
			}
			if (entity.alliance_id) {
				jobs.push(fetchEntity("alliances", entity.alliance_id).then(function (alliance) {
					related.alliance = alliance;
				}).catch(function () {}));
			}
		}

		if (entityType === "alliance" && entity.executor_corporation_id) {
			jobs.push(fetchEntity("corporations", entity.executor_corporation_id).then(function (corp) {
				related.executor = corp;
			}).catch(function () {}));
		}

		await Promise.all(jobs);
		return related;
	}

	function renderEntity(entity, related) {
		const standard404 = document.getElementById("zkb-404-standard");
		if (standard404) standard404.classList.add("d-none");

		root.className = "row m-0 p-0 overview-top";
		root.innerHTML = [
			"<h1 class=\"visually-hidden\">" + escapeHtml(entity.name + " | " + config.label) + "</h1>",
			"<div class=\"col-12 col-md-6 float-start\" style=\"margin:0;padding:0;padding-right:2em;\">",
				"<table class=\"table table-sm table-borderless m-0 p-0\"><tbody>",
					"<tr class=\"d-table-row d-md-none\"><td colspan=\"2\">" + entityImage(entity) + "</td></tr>",
					"<tr>",
						"<td class=\"d-none d-md-table-cell\" style=\"width:130px;border-top:none;margin:0;padding:0;\">" + entityImage(entity) + "</td>",
						"<td style=\"border-top:none;margin:0;padding:0;\">",
							"<div class=\"float-start\" itemscope>",
								"<table class=\"table table-sm table-borderless\"><tbody>",
									detailRows(entity, related),
									websiteRow(entity),
								"</tbody></table>",
							"</div>",
						"</td>",
					"</tr>",
				"</tbody></table>",
			"</div>"
		].join("");
	}

	function showStandard404() {
		const standard404 = document.getElementById("zkb-404-standard");
		if (standard404) standard404.classList.remove("d-none");
		root.className = "d-none";
		root.textContent = "";
		root.innerHTML = "";
	}

	function entityImage(entity) {
		return [
			"<div class=\"float-start\" style=\"margin-right:0.5em;\">",
				"<a href=\"" + escapeHtml(config.zkbPath) + "\" rel=\"tooltip\" title=\"" + escapeHtml(entity.name) + "\">",
					"<img class=\"eveimage " + config.imageClass + "\" src=\"" + escapeHtml(config.image) + "\" style=\"height:128px;width:128px;\" alt=\"" + escapeHtml(config.imageAlt) + "\" loading=\"lazy\" decoding=\"async\">",
				"</a>",
			"</div>"
		].join("");
	}

	function detailRows(entity, related) {
		const rows = [];

		if (type === "character") {
			addLinkedRow(rows, "Character", "character", id, entity);
			addLinkedRow(rows, "Corporation", "corporation", entity.corporation_id, related.corporation);
			addLinkedRow(rows, "Alliance", "alliance", entity.alliance_id, related.alliance);
			addRow(rows, "Birthday", dateOnly(entity.birthday));
			addRow(rows, "Sec. Status", entity.security_status == null ? "" : Number(entity.security_status).toFixed(2));
		}

		if (type === "corporation") {
			addLinkedRow(rows, "Corporation", "corporation", id, entity);
			addLinkedRow(rows, "CEO", "character", entity.ceo_id, related.ceo);
			addLinkedRow(rows, "Alliance", "alliance", entity.alliance_id, related.alliance);
			addRow(rows, "Founded", dateOnly(entity.date_founded));
			addRow(rows, "Members", entity.member_count == null ? "" : Number(entity.member_count).toLocaleString());
			addRow(rows, "War Eligible", entity.war_eligible == null ? "" : (entity.war_eligible ? "Yes" : "No"));
		}

		if (type === "alliance") {
			addLinkedRow(rows, "Alliance", "alliance", id, entity);
			addLinkedRow(rows, "Executor", "corporation", entity.executor_corporation_id, related.executor);
			addRow(rows, "Founded", dateOnly(entity.date_founded));
		}

		return rows.join("");
	}

	function addRow(rows, label, value) {
		if (value === null || value === undefined || value === "") return;
		rows.push("<tr><th>" + escapeHtml(label) + ":</th><td>" + escapeHtml(value) + "</td></tr>");
	}

	function addLinkedRow(rows, label, linkType, entityID, entity) {
		if (!entityID) return;
		const name = entity && entity.name ? entity.name : label + " " + entityID;
		const href = "/" + linkType + "/" + entityID + "/";
		rows.push("<tr><th>" + escapeHtml(label) + ":</th><td><a class=\"wrapplease\" href=\"" + escapeHtml(href) + "\">" + escapeHtml(name) + "</a>" + linkedTicker(linkType, entity) + "</td></tr>");
	}

	function linkedTicker(linkType, entity) {
		if (!entity || !entity.ticker) return "";
		return " " + escapeHtml(ticker(linkType, entity.ticker));
	}

	function websiteRow(entity) {
		const url = type === "corporation" ? safeUrl(entity.url) : "";
		if (!url) return "";
		return "<tr><th>Website:</th><td><a class=\"wrapplease\" href=\"" + escapeHtml(url) + "\" target=\"_blank\" rel=\"nofollow ugc noopener noreferrer\">" + escapeHtml(url) + "</a></td></tr>";
	}

	function ticker(entityType, value) {
		if (!value) return "";
		if (entityType === "alliance") return "<" + value + ">";
		if (entityType === "corporation") return "[" + value + "]";
		return "";
	}

	function dateOnly(value) {
		return value ? String(value).split("T")[0] : "";
	}

	function safeUrl(value) {
		if (!value) return "";
		try {
			const url = new URL(value, window.location.href);
			return /^https?:$/.test(url.protocol) ? url.href : "";
		} catch (ex) {
			return "";
		}
	}

	function escapeHtml(value) {
		return String(value).replace(/[&<>"']/g, function (char) {
			return {
				"&": "&amp;",
				"<": "&lt;",
				">": "&gt;",
				"\"": "&quot;",
				"'": "&#039;"
			}[char];
		});
	}
})();
