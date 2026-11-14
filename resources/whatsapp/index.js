// Fork-only: the built-in WhatsApp gateway (container coolify-whatsapp), started by App\Services\WhatsappGateway.
// It links one WhatsApp account through WhatsApp Web (scan the QR code in Settings > AI assistant),
// forwards incoming chats (and the owner's own "Message yourself" chat) to the app and sends the messages Coolify asks for.
// Every request in both directions carries the shared secret in the X-Whatsapp-Secret header.
const fs = require('node:fs');
const http = require('node:http');
const crypto = require('node:crypto');
const QRCode = require('qrcode');
const { Client, LocalAuth } = require('whatsapp-web.js');

const SECRET = process.env.SECRET || '';
const WEBHOOK_URL = process.env.WEBHOOK_URL || '';
const PORT = Number(process.env.PORT || 3000);

if (SECRET.length < 32) {
    console.error('SECRET is missing.');
    process.exit(1);
}

let state = 'starting';
let qr = null;
let me = null;

const client = new Client({
    authStrategy: new LocalAuth({ dataPath: '/data' }),
    puppeteer: {
        executablePath: process.env.PUPPETEER_EXECUTABLE_PATH,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    },
});

client.on('qr', async (code) => {
    state = 'qr';
    qr = await QRCode.toDataURL(code, { margin: 1, width: 280 });
});
client.on('ready', () => {
    state = 'ready';
    qr = null;
    me = client.info?.wid?.user ?? null;
    console.log('WhatsApp linked:', me);
});
client.on('auth_failure', () => { state = 'disconnected'; });
client.on('disconnected', (reason) => {
    console.log('WhatsApp disconnected:', reason);
    state = 'disconnected';
    me = null;
    // Exit so Docker restarts the container and a new QR code is shown.
    setTimeout(() => process.exit(1), 2000);
});

// Texts this gateway is sending: in the owner's "Message yourself" chat they come back as messages from me.
const outgoing = new Set();

// message_create also fires for messages sent from the linked phone, so the owner can use "Message yourself".
client.on('message_create', async (message) => {
    if (message.type !== 'chat' || message.from.endsWith('@g.us') || message.from === 'status@broadcast' || !WEBHOOK_URL) {
        return;
    }
    if (message.fromMe && outgoing.delete(message.body)) {
        return;
    }
    try {
        let number;
        if (message.fromMe) {
            const chat = await message.getChat();
            const self = message.to === message.from || chat.id.user === me || chat.id._serialized === client.info?.wid?._serialized;
            if (!self) {
                return; // The owner writing to someone else.
            }
            number = me;
        } else {
            number = (await message.getContact()).number;
            // WhatsApp now hides many senders behind a private id (…@lid); ask WhatsApp for the phone number behind it.
            if (message.from.endsWith('@lid')) {
                const [match] = await client.getContactLidAndPhone([message.from]).catch(() => []);
                number = String(match?.pn || '').split('@')[0] || number;
            }
        }
        const response = await fetch(WEBHOOK_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Whatsapp-Secret': SECRET },
            body: JSON.stringify({ id: message.id._serialized, number: number || '', body: message.body }),
        });
        console.log('Forwarded a message from', number ? '…' + String(number).slice(-4) : 'unknown', message.fromMe ? '(own chat)' : '', '->', response.status);
    } catch (error) {
        console.error('Could not forward a message:', error.message);
    }
});

function authorized(request) {
    const given = Buffer.from(String(request.headers['x-whatsapp-secret'] || ''));
    const expected = Buffer.from(SECRET);
    return given.length === expected.length && crypto.timingSafeEqual(given, expected);
}

function reply(response, status, body) {
    response.writeHead(status, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify(body));
}

function readJson(request) {
    return new Promise((resolve, reject) => {
        let data = '';
        request.on('data', (chunk) => {
            data += chunk;
            if (data.length > 1_000_000) {
                request.destroy();
                reject(new Error('Too large'));
            }
        });
        request.on('end', () => {
            try { resolve(JSON.parse(data || '{}')); } catch (error) { reject(error); }
        });
    });
}

http.createServer(async (request, response) => {
    if (!authorized(request)) {
        return reply(response, 401, { error: 'unauthorized' });
    }
    try {
        if (request.method === 'GET' && request.url === '/status') {
            return reply(response, 200, { state, qr, me });
        }
        if (request.method === 'POST' && request.url === '/send') {
            if (state !== 'ready') {
                return reply(response, 409, { error: 'WhatsApp is not linked.' });
            }
            const { to, text } = await readJson(request);
            const id = await client.getNumberId(String(to || '').replace(/\D/g, ''));
            if (!id) {
                return reply(response, 404, { error: 'This number is not on WhatsApp.' });
            }
            outgoing.add(String(text || ''));
            setTimeout(() => outgoing.delete(String(text || '')), 60_000);
            await client.sendMessage(id._serialized, String(text || ''));
            return reply(response, 200, { sent: true });
        }
        if (request.method === 'POST' && request.url === '/logout') {
            await client.logout().catch(() => {});
            reply(response, 200, { ok: true });
            setTimeout(() => process.exit(0), 500);
            return;
        }
        reply(response, 404, { error: 'not found' });
    } catch (error) {
        reply(response, 500, { error: error.message });
    }
}).listen(PORT, () => console.log('WhatsApp gateway on port', PORT));

// A hard stop (server reboot) leaves Chromium's profile lock behind, and Chromium then refuses to start. Only this container uses the profile.
for (const name of ['SingletonLock', 'SingletonCookie', 'SingletonSocket']) {
    fs.rmSync(`/data/session/${name}`, { force: true });
}

client.initialize();
