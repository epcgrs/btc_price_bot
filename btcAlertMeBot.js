const TelegramBot = require('node-telegram-bot-api');
const sqlite3 = require('sqlite3').verbose();
const axios = require('axios');
const fs = require('fs');
const path = require('path');
const dotenv = require('dotenv');
dotenv.config();

const TOKEN = process.env.TOKEN;

const API_URL = `https://api.telegram.org/bot${TOKEN}/`;
const DB_FILE = "alerts.db";

const db = new sqlite3.Database(DB_FILE);
db.serialize(() => {
    db.run(`CREATE TABLE IF NOT EXISTS alerts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        alert_type TEXT NOT NULL,
        percent_change REAL NOT NULL,
        set_time INTEGER NOT NULL,
        initial_price REAL NOT NULL
    )`);
    db.run(`CREATE TABLE IF NOT EXISTS prices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        price REAL NOT NULL,
        timestamp INTEGER NOT NULL
    )`);
});

const bot = new TelegramBot(TOKEN, { polling: true });

async function getBitcoinPrice() {
    try {
        const response = await axios.get("https://api.binance.com/api/v3/ticker/price?symbol=BTCUSDT");
        return parseFloat(response.data.price);
    } catch (error) {
        console.log("Erro ao obter preço do Bitcoin", error);
        return null;
    }
}

async function getHistoricalPrices() {
    try {
        const response = await axios.get("https://api.binance.com/api/v3/klines?symbol=BTCUSDT&interval=1d&limit=200");
        return response.data.map(row => parseFloat(row[4]));
    } catch (error) {
        console.log("Erro ao obter preços históricos", error);
        return [];
    }
}

async function calculateMayerMultiple() {
    const current_price = await getBitcoinPrice();
    const prices = await getHistoricalPrices();
    if (prices.length === 0) return null;

    const sma_200 = prices.reduce((a, b) => a + b, 0) / prices.length;
    return {
        price: current_price,
        sma_200,
        mayer_multiple: current_price / sma_200
    };
}

async function checkAlerts() {
    const current_price = await getBitcoinPrice();
    if (!current_price) return;

    db.all("SELECT * FROM alerts", [], (err, rows) => {
        if (err) return console.error(err);
        rows.forEach(row => {
            const price_diff = ((current_price - row.initial_price) / row.initial_price) * 100;
            if (Math.abs(price_diff) >= row.percent_change) {
                if (row.alert_type === 'normal') {
                    bot.sendMessage(row.user_id, `📈 Alerta: O Bitcoin variou ${price_diff.toFixed(2)}%. Desde o valor inicial de $${row.initial_price.toFixed(2)}. Preço atual: $${current_price.toFixed(2)}.`);
                    db.run("DELETE FROM alerts WHERE id = ?", [row.id]);
                }
            }
    
            if (row.alert_type === 'midnight') {
                const last_midnight = Math.floor(new Date().setHours(0, 0, 0, 0) / 1000);
                
                if (row.set_time < last_midnight) { // Só atualiza se já passou um novo dia
                    bot.sendMessage(row.user_id, `📈📈 Alerta: O Bitcoin variou ${price_diff.toFixed(2)}%. Desde o valor inicial de $${row.initial_price.toFixed(2)}. Preço atual: $${current_price.toFixed(2)}.`);

                    db.run("UPDATE alerts SET set_time = ?, initial_price = ? WHERE user_id = ? AND alert_type = 'midnight'", 
                        [last_midnight, current_price, row.user_id]);
                }
            }
        });
    });
    
}

bot.on('message', async (msg) => {
    const chat_id = msg.chat.id;
    const text = msg.text || "";

    if (text.startsWith("/start")) {
        bot.sendMessage(chat_id, "👋 Olá! Use os comandos:\n📌 /alerta <percentual>\n❌ /cancelar_alerta\n📈 /preco\n📊 /mayer\n🕛 /alert_midnight <percentual>\n\nConsidere fazer uma doação via Lightning para o criador: https://coinos.io/mmzero");
    } else if (text.startsWith("/alerta")) {
        const args = text.split(" ");
        if (args[1] && !isNaN(args[1])) {
            const percent = parseFloat(args[1]);
            const current_price = await getBitcoinPrice();
            if (!current_price) return bot.sendMessage(chat_id, "⚠️ Erro ao obter o preço do Bitcoin.");
            db.run("INSERT INTO alerts (user_id, alert_type, percent_change, set_time, initial_price) VALUES (?, ?, ?, ?, ?)", 
                [chat_id, 'normal', percent, Date.now(), current_price]);
            bot.sendMessage(chat_id, `✅ Alerta configurado para ${percent}% de variação no Bitcoin.`);
        } else {
            bot.sendMessage(chat_id, "⚠️ Uso: /alerta <percentual>");
        }
    } else if (text.startsWith("/cancelar_alerta")) {
        db.run("DELETE FROM alerts WHERE user_id = ?", [chat_id]);
        bot.sendMessage(chat_id, "✅ Seu alerta foi removido.");
    } else if (text.startsWith("/preco")) {
        const price = await getBitcoinPrice();
        if (!price) return bot.sendMessage(chat_id, "⚠️ Erro ao obter o preço do Bitcoin.");
        
        bot.sendMessage(chat_id, `📈 O preço atual do Bitcoin é **$${price.toFixed(2)}**.`);
    } else if (text.startsWith("/mayer")) {
        const mayer = await calculateMayerMultiple();
        if (!mayer) return bot.sendMessage(chat_id, "⚠️ Erro ao calcular Mayer Multiple.");
        
        bot.sendMessage(chat_id, `📊 **Mayer Multiple**: ${mayer.mayer_multiple.toFixed(2)}\n💰 **Preço Atual**: $${mayer.price.toFixed(2)}\n📉 **Média Móvel 200d**: $${mayer.sma_200.toFixed(2)}`);
    } else if (text.startsWith("/alert_midnight")) {
        const args = text.split(" ");
        if (args[1] && !isNaN(args[1])) {
            const percent = parseFloat(args[1]);
            const current_price = await getBitcoinPrice();
            if (!current_price) return bot.sendMessage(chat_id, "⚠️ Erro ao obter o preço do Bitcoin.");
            db.run("INSERT INTO alerts (user_id, alert_type, percent_change, set_time, initial_price) VALUES (?, ?, ?, ?, ?)", 
                [chat_id, 'midnight', percent, Math.floor(new Date().setHours(0, 0, 0, 0) / 1000), current_price]);
            bot.sendMessage(chat_id, `✅ Alerta de meia-noite configurado para ${percent}% de variação.`);
        } else {
            bot.sendMessage(chat_id, "⚠️ Uso: /alert_midnight <percentual>");
        }
    } else if (text.startsWith("/help")) {
        bot.sendMessage(chat_id, "📌 /alerta <percentual> = avisa a variação a partir da criação do alerta.\n❌ /cancelar_alerta = cancela seus alertas.\n📈 /preco = mostra a cotação atual. \n📊 /mayer = mostra o múltiplo de mayer. \n🕛 /alert_midnight <percentual> = Cria um alerta  usando preço atual, mas atualiza todo dia a cotação para que leve em consideração o preço desde 00:00h. \n\nConsidere fazer uma doação via Lightning para o criador: https://coinos.io/mmzero");
    } else {
        bot.sendMessage(chat_id, "⚠️ Comando desconhecido.");
    }
});

setInterval(checkAlerts, 5000);
