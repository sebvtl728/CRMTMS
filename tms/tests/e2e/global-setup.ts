import { execFileSync } from 'node:child_process';

// Recreates the e2e database and installs a fresh organisme; the administrator's
// activation link (single use) is handed to the tests through the environment.
export default function globalSetup() {
    const output = execFileSync('bash', ['tests/e2e/prepare.sh'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] });
    const url = output.trim().split('\n').pop() ?? '';

    if (!/\/activation\/[A-Za-z0-9]+$/.test(url)) {
        throw new Error(`Activation URL not found in the installer output: ${output}`);
    }

    process.env.E2E_ACTIVATION_URL = url;
}
