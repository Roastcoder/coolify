// Fork-only: the built-in WhatsApp gateway (container coolify-whatsapp), started by App\Services\WhatsappGateway.
// It links one WhatsApp account through WhatsApp Web (scan the QR code in Settings > AI assistant),
// forwards incoming chats (and the owner's own "Message yourself" chat) to the app and sends the messages Coolify asks for.
// Every request in both directions carries the shared secret in the X-Whatsapp-Secret header.
const fs = require('node:fs');
const http = require('node:http');
const crypto = require('node:crypto');
const QRCode = require('qrcode');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');

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
let meLid = null; // The linked account's private id (…@lid): newer WhatsApp uses it for the "Message yourself" chat.

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
    client.getContactLidAndPhone([client.info.wid._serialized])
        .then(([match]) => { meLid = match?.lid ?? null; })
        .catch(() => {});
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

// Files people send (documents and photos), kept for 15 minutes so the app can fetch the one it needs from GET /media/<id>.
const media = new Map();
const MEDIA_TYPES = ['document', 'image'];
const MAX_MEDIA_BYTES = 15 * 1024 * 1024;

// message_create also fires for messages sent from the linked phone, so the owner can use "Message yourself".
const mask = (id) => '…' + String(id || '').split('@')[0].slice(-4) + (String(id).includes('@lid') ? '@lid' : '');

client.on('message_create', async (message) => {
    console.log('Event:', message.type, message.fromMe ? 'own' : 'incoming', 'from', mask(message.from), 'to', mask(message.to));
    const isMedia = MEDIA_TYPES.includes(message.type) && message.hasMedia && !message.fromMe;
    if ((message.type !== 'chat' && !isMedia) || message.from.endsWith('@g.us') || message.from === 'status@broadcast' || !WEBHOOK_URL) {
        return;
    }
    if (message.fromMe && outgoing.delete(message.body)) {
        return;
    }
    try {
        let number;
        if (message.fromMe) {
            const chat = await message.getChat();
            const ownIds = [client.info?.wid?._serialized, meLid].filter(Boolean);
            const self = message.to === message.from || ownIds.includes(message.to) || ownIds.includes(chat.id._serialized);
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
        let file = null;
        if (isMedia) {
            const download = await message.downloadMedia().catch(() => null);
            const size = download ? Math.floor(download.data.length * 3 / 4) : 0;
            if (download && size <= MAX_MEDIA_BYTES) {
                const id = crypto.randomUUID();
                media.set(id, { mimetype: download.mimetype, data: download.data, filename: download.filename || null });
                setTimeout(() => media.delete(id), 15 * 60_000);
                file = { id, mimetype: download.mimetype, filename: download.filename || null, size };
            }
        }
        const response = await fetch(WEBHOOK_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Whatsapp-Secret': SECRET },
            // A photo or document comes with its caption as the body.
            body: JSON.stringify({ id: message.id._serialized, number: number || '', body: isMedia ? (message.body || '') : message.body, media: file }),
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
            if (data.length > 25_000_000) { // Room for a document of about 15 MB as base64.
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
            const { to, text, file } = await readJson(request);
            const id = await client.getNumberId(String(to || '').replace(/\D/g, ''));
            if (!id) {
                return reply(response, 404, { error: 'This number is not on WhatsApp.' });
            }
            outgoing.add(String(text || ''));
            setTimeout(() => outgoing.delete(String(text || '')), 60_000);
            if (file && file.data) {
                // A document (for example a PDF) with the text as its caption.
                const attachment = new MessageMedia(String(file.mimetype || 'application/pdf'), String(file.data), String(file.filename || 'document.pdf'));
                await client.sendMessage(id._serialized, attachment, { caption: String(text || ''), sendMediaAsDocument: true });
            } else {
                await client.sendMessage(id._serialized, String(text || ''));
            }
            return reply(response, 200, { sent: true });
        }
        if (request.method === 'GET' && request.url.startsWith('/media/')) {
            const file = media.get(decodeURIComponent(request.url.slice('/media/'.length)));
            return file ? reply(response, 200, file) : reply(response, 404, { error: 'This file is gone. Send it again.' });
        }
        // The owner's own 1:1 chats, for training a model on their style. Names are replaced by Me / Them; groups,
        // broadcasts and the bot's own chat are left out. Nothing is stored here: the caller gets it as JSON.
        if (request.method === 'GET' && request.url === '/export') {
            if (state !== 'ready') {
                return reply(response, 409, { error: 'WhatsApp is not linked.' });
            }
            const own = [client.info?.wid?._serialized, meLid].filter(Boolean);
            const pad = (n) => String(n).padStart(2, '0');
            const chats = [];
            for (const chat of (await client.getChats()).slice(0, 300)) {
                if (chat.isGroup || chat.id._serialized.endsWith('@broadcast') || own.includes(chat.id._serialized)) {
                    continue;
                }
                const messages = (await chat.fetchMessages({ limit: 500 })).filter((m) => m.type === 'chat' && m.body);
                if (!messages.some((m) => m.fromMe)) {
                    continue;
                }
                chats.push(messages.map((m) => {
                    const d = new Date(m.timestamp * 1000);
                    const when = `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${String(d.getFullYear()).slice(2)}, ${pad(d.getHours())}:${pad(d.getMinutes())}`;
                    return `${when} - ${m.fromMe ? 'Me' : 'Them'}: ${m.body}`;
                }).join('\n'));
            }
            return reply(response, 200, { chats });
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
