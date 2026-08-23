/**
 * A stand-in for the other machine in a transfer.
 *
 * The transfer page asks a server for permission before anything moves, and a
 * test needs that ask to succeed — an address that refuses or does not answer
 * leaves the transfer `failed`, which is a different state with different
 * buttons. This server cannot play the part itself: `artisan serve` is single
 * threaded, so a request it makes to itself waits on the process already busy
 * serving the page that made it.
 *
 * It answers any POST with a request id, which is all asking looks at.
 */
import { createServer } from 'node:http';

const PORT = Number(process.env.STUB_SOURCE_PORT || 8198);

createServer((request, response) => {
    response.writeHead(200, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify({
        id: 'e2e-request',
        code: 'E2E123',
        state: 'pending',
    }));
}).listen(PORT, '127.0.0.1');
