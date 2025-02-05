<?php

ini_set('max_execution_time', 0); // 0 = sem limite
ignore_user_abort(true); // Impede que o script pare se a conexão for fechada

$TOKEN = "TOKEN";
$API_URL = "https://api.telegram.org/bot$TOKEN/";
$LOCK_FILE = __DIR__ . "/btcAlertMeBot.lock";
// Definir arquivo de banco de dados
$DB_FILE = "alerts.db";

// Se o lock file existe e o processo ainda está rodando, sai do script
if (file_exists($LOCK_FILE)) {
    $pid = file_get_contents($LOCK_FILE);
    if (posix_getpgid((int)$pid)) {
        exit("Bot já está rodando. Saindo...\n");
    }
}

// Cria o lock file com o ID do processo atual
file_put_contents($LOCK_FILE, getmypid());

// Criar banco de dados SQLite se não existir
$db = new SQLite3($DB_FILE);
$db->exec("CREATE TABLE IF NOT EXISTS alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    alert_type TEXT NOT NULL,
    percent_change REAL NOT NULL,
    set_time INTEGER NOT NULL,
    initial_price REAL NOT NULL
)");
$db->exec("CREATE TABLE IF NOT EXISTS prices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    price REAL NOT NULL,
    timestamp INTEGER NOT NULL
)");


// Função para obter o preço atual do Bitcoin
function getBitcoinPrice() {
    global $db;
    $url = "https://api.binance.com/api/v3/ticker/price?symbol=BTCUSDT";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return $data["price"] ?? null;
}


// Função para obter preços históricos (últimos 200 dias)
function getHistoricalPrices() {
    $url = "https://api.binance.com/api/v3/klines?symbol=BTCUSDT&interval=1d&limit=200";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Desativa a verificação SSL
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Desativa a verificação do host

    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);

    $prices = [];
    foreach ($data as $row) {
        $prices[] = floatval($row[4]); // Preço de fechamento
    }
    return $prices;
}

// Função para calcular o Mayer Multiple
function calculateMayerMultiple() {
    $current_price = getBitcoinPrice();
    $prices = getHistoricalPrices();
    $sma_200 = array_sum($prices) / count($prices);
    $mayer_multiple = $current_price / $sma_200;

    return [
        "price" => $current_price,
        "sma_200" => $sma_200,
        "mayer_multiple" => $mayer_multiple
    ];
}

// Função para processar os comandos recebidos pelo bot
function processMessage($update) {
    global $db, $API_URL;

    $message = $update["message"];
    $chat_id = $message["chat"]["id"];
    $text = $message["text"] ?? "";

    if (strpos($text, "/start") === 0) {
        sendMessage($chat_id, "👋 Olá! Use os comandos:\n📌 /alerta <percentual>\n❌ /cancelar_alerta\n📈 /preco\n📊 /mayer\n🕛 /alert_midnight <percentual> \n\n considere fazer uma doação via lightning para o criador: https://coinos.io/mmzero");
    } elseif (strpos($text, "/alerta") === 0) {
        $args = explode(" ", $text);
        if (isset($args[1]) && is_numeric($args[1])) {
            $percent = floatval($args[1]);
            $time = time();
            $current_price = getBitcoinPrice($time);
            $db->exec("INSERT INTO alerts (user_id, percent_change, alert_type, set_time, initial_price) VALUES ($chat_id, $percent, 'normal', ".$time.", $current_price)");
            sendMessage($chat_id, "✅ Alerta configurado para $percent% de variação no Bitcoin.");
        } else {
            sendMessage($chat_id, "⚠️ Uso: /alerta <percentual>");
        }
    } elseif (strpos($text, "/alert_midnight") === 0) {
        $args = explode(" ", $text);
        if (isset($args[1]) && is_numeric($args[1])) {
            $time = time();
            $current_price = getBitcoinPrice($time);
            $percent = floatval($args[1]);
            $db->exec("INSERT INTO alerts (user_id, alert_type, percent_change, set_time, initial_price) VALUES ($chat_id, 'midnight', $percent, " . $time . ", $current_price)");

            sendMessage($chat_id, "✅ Alerta configurado para $percent% de variação no Bitcoin.");
        } else {
            sendMessage($chat_id, "⚠️ Uso: /alert_midnight <percentual>");
        }
    } elseif (strpos($text, "/cancelar_alerta") === 0) {
        $db->exec("DELETE FROM alerts WHERE user_id = $chat_id");
        sendMessage($chat_id, "✅ Seu alerta foi removido.");
    } elseif (strpos($text, "/preco") === 0) {
        $price = getBitcoinPrice();
        sendMessage($chat_id, "📈 O preço atual do Bitcoin é **$" . number_format($price, 2) . "**.");
    } elseif (strpos($text, "/mayer") === 0) {
        $mayer = calculateMayerMultiple();
        $response = "📊 **Mayer Multiple**: " . number_format($mayer["mayer_multiple"], 2) . "\n" . 
                    "💰 **Preço Atual**: $" . number_format($mayer["price"], 2) . "\n" . 
                    "📉 **Média Móvel 200d**: $" . number_format($mayer["sma_200"], 2);
        sendMessage($chat_id, $response);
    } else {
        sendMessage($chat_id, "⚠️ Comando desconhecido.");
    }
}

// Função para obter o preço do Bitcoin no momento do alerta
function getBitcoinPriceAtTime($timestamp) {
    global $db;
    $query = $db->querySingle("SELECT price FROM prices WHERE timestamp <= $timestamp ORDER BY timestamp DESC LIMIT 1", true);
    return $query['price'] ?? 0;
}

// Função para enviar mensagens ao usuário
function sendMessage($chat_id, $text) {
    global $API_URL;
    $text = str_replace(['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'], 
                        ['\_', '\*', '\[', '\]', '\(', '\)', '\~', '\`', '\>', '\#', '\+', '\-', '\=', '\|', '\{', '\}', '\.', '\!'], $text);
    $url = $API_URL . "sendMessage?chat_id=$chat_id&text=" . urlencode($text) . "&parse_mode=MarkdownV2";
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    curl_exec($ch);
    curl_close($ch);
}

// Função para verificar alertas e notificar usuários
function checkAlerts() {
    global $db, $API_URL;

    $current_price = getBitcoinPrice();
    $current_time = time();
    $results = $db->query("SELECT user_id, percent_change, alert_type, set_time, initial_price FROM alerts");

    while ($row = $results->fetchArray()) {
        $user_id = $row["user_id"];
        $threshold = $row["percent_change"];
        $alert_type = $row["alert_type"];
        $set_time = $row["set_time"];
        $initial_price = $row["initial_price"];

        if ($alert_type == 'midnight') {

            // Calcular a variação percentual
            $price_diff = (($current_price - $initial_price) / $initial_price) * 100;

            // Se houve variação maior ou igual ao limiar, dispara alerta
            if (abs($price_diff) >= $threshold) {
                
                sendMessage($user_id, "📈📈 Alerta de variação: O preço do Bitcoin variou " . number_format($price_diff, 2) . "% desde o valor inicial de $ " . number_format($initial_price, 2) . ". O preço atual é $ " . number_format($current_price, 2) . ". \n\n Bora Stacker mais sats Magnata 🚀🚀🚀");
            }

            // Atualizar o alerta para o próximo dia e definir novo preço inicial
            $db->exec("UPDATE alerts SET set_time = " . strtotime('midnight', $current_time) . ", initial_price = $current_price WHERE user_id = $user_id AND alert_type = 'midnight'");
    
        } else {
            // Verificar se a variação percentual atingiu o limite
            $price_diff = (($current_price - $initial_price) / $initial_price) * 100;

            if (abs($price_diff) >= $threshold) {
                sendMessage($user_id, "📈 Alerta de variação: O Bitcoin variou " . number_format($price_diff, 2) . "% desde o valor inicial de $ " . number_format($initial_price, 2) . ". O preço atual é $ " . number_format($current_price, 2) . ".\n\n Bora Stacker mais sats Magnata 🚀🚀🚀");
                
                // Remover o alerta após disparo
                $db->exec("DELETE FROM alerts WHERE user_id = $user_id AND alert_type = 'normal'");
            }
        }
    }
}


// Função para rodar o bot continuamente
function runBot() {
    global $API_URL;

    $offset = 0;
    while (true) {
        $url = $API_URL . "getUpdates?offset=$offset&timeout=10";

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $updates = curl_exec($ch);
        curl_close($ch);

        $updates = json_decode($updates, true);

        if (isset($updates["result"])) {
            foreach ($updates["result"] as $update) {
                $offset = $update["update_id"] + 1;
                processMessage($update);
            }
        }

        checkAlerts();
        sleep(5);
    }
}

// Iniciar o bot
runBot();

// Remove o lock file ao finalizar
unlink($LOCK_FILE);