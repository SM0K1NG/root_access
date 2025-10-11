<?php
session_start();

// Configurações
$config = [
    'title' => 'Database Explorer',
    'version' => '1.0',
    'max_query_length' => 10000,
    'max_results' => 1000,
    'allowed_hosts' => ['localhost', '127.0.0.1', '::1', 'mysqlhost']
];

// Funções principais
function connectDB($host, $username, $password, $database = null) {
    try {
        $dsn = "mysql:host={$host};charset=utf8mb4";
        if ($database) {
            $dsn .= ";dbname={$database}";
        }
        
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        
        return $pdo;
    } catch (PDOException $e) {
        return ['error' => $e->getMessage()];
    }
}

function executeQuery($pdo, $query) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        
        if (stripos($query, 'SELECT') === 0 || stripos($query, 'SHOW') === 0 || stripos($query, 'DESCRIBE') === 0 || stripos($query, 'EXPLAIN') === 0) {
            return ['success' => true, 'data' => $stmt->fetchAll()];
        } else {
            return ['success' => true, 'affected' => $stmt->rowCount()];
        }
    } catch (PDOException $e) {
        return ['error' => $e->getMessage()];
    }
}

function getTables($pdo) {
    $result = executeQuery($pdo, "SHOW TABLES");
    if (isset($result['data'])) {
        $tables = [];
        foreach ($result['data'] as $row) {
            $tables[] = array_values($row)[0];
        }
        return $tables;
    }
    return [];
}

function getDatabases($pdo) {
    $result = executeQuery($pdo, "SHOW DATABASES");
    if (isset($result['data'])) {
        $databases = [];
        foreach ($result['data'] as $row) {
            $databases[] = array_values($row)[0];
        }
        return $databases;
    }
    return [];
}

function isValidQuery($query) {
    $query = preg_replace('/--.*$/m', '', $query);
    $query = preg_replace('/\/\*.*?\*\//s', '', $query);
    
    if (strlen($query) > $GLOBALS['config']['max_query_length']) {
        return false;
    }
    
    $dangerousCommands = ['DROP', 'DELETE', 'TRUNCATE', 'ALTER', 'CREATE', 'INSERT', 'UPDATE', 'GRANT', 'REVOKE', 'FLUSH', 'RESET', 'SHUTDOWN', 'KILL'];
    $queryUpper = strtoupper($query);
    
    foreach ($dangerousCommands as $cmd) {
        if (strpos($queryUpper, $cmd) !== false) {
            return false;
        }
    }
    
    return true;
}

// Processar ações
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false];

switch ($action) {
    case 'connect':
        $host = $_POST['host'] ?? 'localhost';
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $database = $_POST['database'] ?? '';
        
        if (!in_array($host, $config['allowed_hosts']) && !filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $host)) {
            $response = ['error' => 'Host não permitido'];
            break;
        }
        
        $pdo = connectDB($host, $username, $password, $database);
        
        if (isset($pdo['error'])) {
            $response = ['error' => $pdo['error']];
        } else {
            $_SESSION['pdo'] = $pdo;
            $_SESSION['db_config'] = compact('host', 'username', 'database');
            $response = ['success' => true, 'message' => 'Conectado com sucesso!'];
        }
        break;
        
    case 'get_databases':
        if (isset($_SESSION['pdo'])) {
            $databases = getDatabases($_SESSION['pdo']);
            $response = ['success' => true, 'databases' => $databases];
        } else {
            $response = ['error' => 'Não conectado ao banco'];
        }
        break;
        
    case 'get_tables':
        if (isset($_SESSION['pdo'])) {
            $tables = getTables($_SESSION['pdo']);
            $response = ['success' => true, 'tables' => $tables];
        } else {
            $response = ['error' => 'Não conectado ao banco'];
        }
        break;
        
    case 'get_table_structure':
        if (isset($_SESSION['pdo']) && isset($_POST['table'])) {
            $structure = executeQuery($_SESSION['pdo'], "DESCRIBE `{$_POST['table']}`");
            $response = $structure;
        } else {
            $response = ['error' => 'Parâmetros inválidos'];
        }
        break;
        
    case 'get_table_data':
        if (isset($_SESSION['pdo']) && isset($_POST['table'])) {
            $limit = min($_POST['limit'] ?? 100, $config['max_results']);
            $offset = $_POST['offset'] ?? 0;
            $data = executeQuery($_SESSION['pdo'], "SELECT * FROM `{$_POST['table']}` LIMIT {$limit} OFFSET {$offset}");
            $response = $data;
        } else {
            $response = ['error' => 'Parâmetros inválidos'];
        }
        break;
        
    case 'execute_query':
        if (isset($_SESSION['pdo']) && isset($_POST['query'])) {
            $query = trim($_POST['query']);
            
            if (!isValidQuery($query)) {
                $response = ['error' => 'Query não permitida ou muito longa'];
                break;
            }
            
            $result = executeQuery($_SESSION['pdo'], $query);
            $response = $result;
        } else {
            $response = ['error' => 'Query não fornecida'];
        }
        break;
        
    case 'disconnect':
        unset($_SESSION['pdo']);
        unset($_SESSION['db_config']);
        $response = ['success' => true, 'message' => 'Desconectado'];
        break;
}

// Retornar JSON para AJAX
if ($action && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $config['title'] ?> v<?= $config['version'] ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .header h1 {
            color: #4a5568;
            font-size: 2.5rem;
            text-align: center;
            margin-bottom: 10px;
        }
        
        .connection-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #4a5568;
        }
        
        .form-group input, .form-group select {
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .main-content {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
            height: calc(100vh - 200px);
        }
        
        .sidebar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }
        
        .sidebar h3 {
            color: #4a5568;
            margin-bottom: 15px;
            font-size: 1.2rem;
        }
        
        .table-list {
            list-style: none;
        }
        
        .table-item {
            padding: 10px;
            margin-bottom: 5px;
            background: #f7fafc;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            border-left: 4px solid transparent;
        }
        
        .table-item:hover {
            background: #edf2f7;
            border-left-color: #667eea;
            transform: translateX(5px);
        }
        
        .table-item.active {
            background: #e6fffa;
            border-left-color: #48bb78;
        }
        
        .content-area {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }
        
        .query-editor {
            margin-bottom: 20px;
        }
        
        .query-editor textarea {
            width: 100%;
            height: 120px;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            resize: vertical;
        }
        
        .query-editor textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .query-buttons {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .results {
            margin-top: 20px;
        }
        
        .results h3 {
            color: #4a5568;
            margin-bottom: 15px;
        }
        
        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        th {
            background: #f7fafc;
            font-weight: 600;
            color: #4a5568;
        }
        
        tr:hover {
            background: #f7fafc;
        }
        
        .status {
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .status.success {
            background: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }
        
        .status.error {
            background: #fed7d7;
            color: #742a2a;
            border: 1px solid #feb2b2;
        }
        
        .status.info {
            background: #bee3f8;
            color: #2a4365;
            border: 1px solid #90cdf4;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .pagination button {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .pagination button:hover {
            background: #f7fafc;
        }
        
        .pagination button.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .loading {
            text-align: center;
            padding: 20px;
            color: #4a5568;
        }
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .hidden {
            display: none;
        }
        
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .tab.active {
            border-bottom-color: #667eea;
            color: #667eea;
            font-weight: 600;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .database-selector {
            margin-bottom: 20px;
        }
        
        .database-selector select {
            width: 100%;
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .quick-action {
            padding: 10px;
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s;
        }
        
        .quick-action:hover {
            background: #edf2f7;
            border-color: #667eea;
        }
        
        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
                height: auto;
            }
            
            .connection-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🗄️ <?= $config['title'] ?> v<?= $config['version'] ?></h1>
            
            <?php if (!isset($_SESSION['pdo'])): ?>
            <form id="connectionForm" class="connection-form">
                <div class="form-group">
                    <label for="host">Host:</label>
                    <input type="text" id="host" name="host" value="localhost" required>
                </div>
                <div class="form-group">
                    <label for="username">Usuário:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Senha:</label>
                    <input type="password" id="password" name="password">
                </div>
                <div class="form-group">
                    <label for="database">Banco de Dados:</label>
                    <input type="text" id="database" name="database" placeholder="Opcional">
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Conectar</button>
                </div>
            </form>
            <?php else: ?>
            <div class="status success">
                ✅ Conectado ao banco: <strong><?= htmlspecialchars($_SESSION['db_config']['database'] ?: 'Servidor MySQL') ?></strong>
                <button type="button" class="btn btn-danger" onclick="disconnect()" style="float: right; padding: 5px 10px; font-size: 12px;">Desconectar</button>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if (isset($_SESSION['pdo'])): ?>
        <div class="main-content">
            <div class="sidebar">
                <div class="tabs">
                    <div class="tab active" onclick="switchTab('databases')">🗂️ Bancos</div>
                    <div class="tab" onclick="switchTab('tables')">📋 Tabelas</div>
                </div>
                
                <div id="databasesTab" class="tab-content active">
                    <h3>🗂️ Bancos de Dados</h3>
                    <div id="databaseList" class="table-list">
                        <div class="loading">
                            <div class="spinner"></div>
                            Carregando bancos...
                        </div>
                    </div>
                </div>
                
                <div id="tablesTab" class="tab-content">
                    <h3>📋 Tabelas</h3>
                    <div id="tableList" class="table-list">
                        <div class="loading">
                            <div class="spinner"></div>
                            Carregando tabelas...
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="content-area">
                <div class="tabs">
                    <div class="tab active" onclick="switchContentTab('editor')">💻 Editor SQL</div>
                    <div class="tab" onclick="switchContentTab('data')">📊 Dados</div>
                </div>
                
                <div id="editorTab" class="tab-content active">
                    <div class="query-editor">
                        <h3>💻 Editor SQL</h3>
                        <textarea id="queryEditor" placeholder="Digite sua query SQL aqui...&#10;Exemplo: SELECT * FROM usuarios LIMIT 10;"></textarea>
                        <div class="query-buttons">
                            <button type="button" class="btn btn-primary" onclick="executeQuery()">Executar</button>
                            <button type="button" class="btn btn-success" onclick="formatQuery()">Formatar</button>
                            <button type="button" class="btn btn-secondary" onclick="clearQuery()">Limpar</button>
                        </div>
                    </div>
                    
                    <div class="quick-actions">
                        <div class="quick-action" onclick="insertQuery('SHOW TABLES;')">SHOW TABLES</div>
                        <div class="quick-action" onclick="insertQuery('SHOW DATABASES;')">SHOW DATABASES</div>
                        <div class="quick-action" onclick="insertQuery('SELECT VERSION();')">SELECT VERSION</div>
                        <div class="quick-action" onclick="insertQuery('SELECT NOW();')">SELECT NOW</div>
                        <div class="quick-action" onclick="insertQuery('SELECT USER();')">SELECT USER</div>
                        <div class="quick-action" onclick="insertQuery('SHOW PROCESSLIST;')">SHOW PROCESSLIST</div>
                    </div>
                    
                    <div id="results" class="results">
                        <h3>📊 Resultados</h3>
                        <div class="status info">
                            Digite uma query SQL e clique em "Executar" para ver os resultados.
                        </div>
                    </div>
                </div>
                
                <div id="dataTab" class="tab-content">
                    <div class="database-selector">
                        <h3>📊 Visualizar Dados</h3>
                        <select id="databaseSelector" onchange="loadTables()">
                            <option value="">Selecione um banco...</option>
                        </select>
                    </div>
                    
                    <div id="tableData" class="results">
                        <div class="status info">
                            Selecione um banco de dados para visualizar as tabelas.
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        let currentTable = null;
        let currentPage = 0;
        const pageSize = 50;

        // Conectar ao banco
        document.getElementById('connectionForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'connect');
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    location.reload();
                } else {
                    showStatus('error', 'Erro: ' + result.error);
                }
            } catch (error) {
                showStatus('error', 'Erro de conexão: ' + error.message);
            }
        });

        // Carregar bancos de dados
        async function loadDatabases() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'action=get_databases'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const databaseList = document.getElementById('databaseList');
                    databaseList.innerHTML = '';
                    
                    result.databases.forEach(db => {
                        const li = document.createElement('li');
                        li.className = 'table-item';
                        li.textContent = db;
                        li.onclick = () => selectDatabase(db);
                        databaseList.appendChild(li);
                    });
                    
                    // Atualizar selector
                    const selector = document.getElementById('databaseSelector');
                    selector.innerHTML = '<option value="">Selecione um banco...</option>';
                    result.databases.forEach(db => {
                        const option = document.createElement('option');
                        option.value = db;
                        option.textContent = db;
                        selector.appendChild(option);
                    });
                } else {
                    showStatus('error', 'Erro ao carregar bancos: ' + result.error);
                }
            } catch (error) {
                showStatus('error', 'Erro: ' + error.message);
            }
        }

        // Carregar tabelas
        async function loadTables() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'action=get_tables'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const tableList = document.getElementById('tableList');
                    tableList.innerHTML = '';
                    
                    result.tables.forEach(table => {
                        const li = document.createElement('li');
                        li.className = 'table-item';
                        li.textContent = table;
                        li.onclick = () => selectTable(table);
                        tableList.appendChild(li);
                    });
                } else {
                    showStatus('error', 'Erro ao carregar tabelas: ' + result.error);
                }
            } catch (error) {
                showStatus('error', 'Erro: ' + error.message);
            }
        }

        // Selecionar banco
        function selectDatabase(dbName) {
            document.querySelectorAll('#databaseList .table-item').forEach(item => {
                item.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Atualizar selector
            document.getElementById('databaseSelector').value = dbName;
            
            // Carregar tabelas do banco selecionado
            loadTables();
        }

        // Selecionar tabela
        async function selectTable(tableName) {
            document.querySelectorAll('#tableList .table-item').forEach(item => {
                item.classList.remove('active');
            });
            event.target.classList.add('active');
            
            currentTable = tableName;
            currentPage = 0;
            
            // Carregar dados da tabela
            await loadTableData(tableName, 0);
            
            // Atualizar editor com query de exemplo
            document.getElementById('queryEditor').value = `SELECT * FROM \`${tableName}\` LIMIT 10;`;
        }

        // Carregar dados da tabela
        async function loadTableData(tableName, offset = 0) {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `action=get_table_data&table=${encodeURIComponent(tableName)}&limit=${pageSize}&offset=${offset}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    displayResults(result.data, tableName);
                } else {
                    showStatus('error', 'Erro ao carregar dados: ' + result.error);
                }
            } catch (error) {
                showStatus('error', 'Erro: ' + error.message);
            }
        }

        // Executar query
        async function executeQuery() {
            const query = document.getElementById('queryEditor').value.trim();
            
            if (!query) {
                showStatus('error', 'Digite uma query SQL');
                return;
            }
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `action=execute_query&query=${encodeURIComponent(query)}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    if (result.data) {
                        displayResults(result.data);
                    } else {
                        showStatus('success', `Query executada com sucesso! ${result.affected} linha(s) afetada(s).`);
                    }
                } else {
                    showStatus('error', 'Erro na query: ' + result.error);
                }
            } catch (error) {
                showStatus('error', 'Erro: ' + error.message);
            }
        }

        // Exibir resultados
        function displayResults(data, tableName = null) {
            const resultsDiv = document.getElementById('results');
            
            if (!data || data.length === 0) {
                resultsDiv.innerHTML = `
                    <h3>📊 Resultados</h3>
                    <div class="status info">Nenhum resultado encontrado.</div>
                `;
                return;
            }
            
            const columns = Object.keys(data[0]);
            const totalRows = data.length;
            
            let html = `
                <h3>📊 Resultados ${tableName ? `- ${tableName}` : ''}</h3>
                <div class="status success">${totalRows} registro(s) encontrado(s)</div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                ${columns.map(col => `<th>${col}</th>`).join('')}
                            </tr>
                        </thead>
                        <tbody>
                            ${data.map(row => `
                                <tr>
                                    ${columns.map(col => `<td>${row[col] || ''}</td>`).join('')}
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
            
            // Adicionar paginação se for uma tabela específica
            if (tableName) {
                html += `
                    <div class="pagination">
                        <button onclick="changePage(-1)" ${currentPage === 0 ? 'disabled' : ''}>« Anterior</button>
                        <span>Página ${currentPage + 1}</span>
                        <button onclick="changePage(1)" ${data.length < pageSize ? 'disabled' : ''}>Próxima »</button>
                    </div>
                `;
            }
            
            resultsDiv.innerHTML = html;
        }

        // Mudar página
        async function changePage(direction) {
            if (!currentTable) return;
            
            const newPage = currentPage + direction;
            if (newPage < 0) return;
            
            currentPage = newPage;
            await loadTableData(currentTable, currentPage * pageSize);
        }

        // Formatar query
        function formatQuery() {
            const textarea = document.getElementById('queryEditor');
            let query = textarea.value;
            
            query = query
                .replace(/\bSELECT\b/gi, 'SELECT')
                .replace(/\bFROM\b/gi, '\nFROM')
                .replace(/\bWHERE\b/gi, '\nWHERE')
                .replace(/\bORDER BY\b/gi, '\nORDER BY')
                .replace(/\bGROUP BY\b/gi, '\nGROUP BY')
                .replace(/\bHAVING\b/gi, '\nHAVING')
                .replace(/\bJOIN\b/gi, '\nJOIN')
                .replace(/\bLEFT JOIN\b/gi, '\nLEFT JOIN')
                .replace(/\bRIGHT JOIN\b/gi, '\nRIGHT JOIN')
                .replace(/\bINNER JOIN\b/gi, '\nINNER JOIN');
            
            textarea.value = query;
        }

        // Limpar query
        function clearQuery() {
            document.getElementById('queryEditor').value = '';
        }

        // Inserir query rápida
        function insertQuery(query) {
            document.getElementById('queryEditor').value = query;
        }

        // Desconectar
        async function disconnect() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'action=disconnect'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    location.reload();
                }
            } catch (error) {
                console.error('Erro ao desconectar:', error);
            }
        }

        // Mostrar status
        function showStatus(type, message) {
            const resultsDiv = document.getElementById('results');
            resultsDiv.innerHTML = `
                <h3>📊 Resultados</h3>
                <div class="status ${type}">${message}</div>
            `;
        }

        // Alternar abas
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            event.target.classList.add('active');
            document.getElementById(tabName + 'Tab').classList.add('active');
        }

        function switchContentTab(tabName) {
            document.querySelectorAll('.content-area .tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.content-area .tab-content').forEach(content => content.classList.remove('active'));
            
            event.target.classList.add('active');
            document.getElementById(tabName + 'Tab').classList.add('active');
        }

        // Carregar dados ao iniciar
        <?php if (isset($_SESSION['pdo'])): ?>
        loadDatabases();
        loadTables();
        <?php endif; ?>

        // Atalhos de teclado
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'Enter') {
                executeQuery();
            }
        });
    </script>
</body>
</html>
