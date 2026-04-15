
let kms = null;
let kms_fetching = 'no';

async function pre_load_kms() {

	// Get the pages current path
	const currentPath = window.location.pathname;
	console.log('Current path:', currentPath);

	// Validate the path is in this format: /<key>/<id>/ or /<key>/<id>/kills|losses|solo/
	const pathRegex = /^\/[^\/]+\/\d+\/(kills|losses|solo)?\/?$/;
	if (pathRegex.test(currentPath)) {
		// if we have kills, losses, or solo then add that, otherwise used mixed
		const pathMatch = currentPath.match(/^\/[^\/]+\/\d+\/(kills|losses|solo)?\/?$/);
		let type = 'mixed';
		if (pathMatch && pathMatch[1]) {
			type = pathMatch[1];
		}
		const url = `${z3}${currentPath}${type}.json`;
		kms_fetching = true;
		kms = fetch(url).then(async response => {
			if (!response.ok) {
				throw new Error(`Network response was not ok: ${response.statusText}`);
			}
			const data = await response.json();
			return pre_prepKills(data);
		}).then(data => {
			kms = data;
			console.log('KMS Data:', kms);
			kms_fetching = 'complete';
		}).catch(error => {
			console.error('There was a problem with the fetch operation:', error);
			kms_fetching = 'no';
		});
	} else {
		kms_fetching = 'no';
	}
}

function pre_prepKills(data) {
	console.log('length', data.length);
	const tbody = document.getElementById('killmailstobdy');
	for (i = 0; i < data.length; i++) {
		killID = data[i];
		const tr = document.createElement('tr');
		tr.id = `kill-${killID}`;
		tr.className = 'fetchme';
		tr.setAttribute('killID', killID);
		if (tbody) {
			tbody.appendChild(tr);
		}
		pre_loadKillRow(killID);
	}
	const kmsLoading = document.getElementById('kms_loading');
	if (kmsLoading) {
		kmsLoading.remove();
	}
}

function pre_loadKillRow(killID, retries = 0) {
	fetch(`/cache/24hour/killlistrow/${killID}/`)
		.then(response => {
			if (!response.ok) {
				throw new Error(`Failed to load kill row: ${response.status}`);
			}
			return response.text();
		})
		.then(data => {
			addKillRow(data, killID);
		})
		.catch(() => {
			retries++;
			if (retries < 3) {
				setTimeout(pre_loadKillRow.bind(null, killID, retries), 1000);
			}
		});
}