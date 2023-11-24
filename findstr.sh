#!/bin/bash

# Função para percorrer recursivamente os diretórios
search_string() {
    for file in "$1"/*; do
        if [ -d "$file" ]; then
            # Se for um diretório, chama a função recursivamente
            search_string "$file"
        elif [ -f "$file" ]; then
            # Se for um arquivo, verifica se a string está presente
            if grep -q "tray" "$file"; then
                echo "String encontrada em: $file"
            fi
        fi
    done
}

# Diretório inicial (pode ser ajustado conforme necessário)
diretorio_inicial="/var/www/html"

# Chama a função com o diretório inicial
search_string "$diretorio_inicial"
