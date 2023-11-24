#!/bin/bash

# Verifica se o número de argumentos é correto
if [ "$#" -ne 2 ]; then
    echo "Uso: $0 <caminho_do_diretorio> <string_a_procurar>"
    exit 1
fi

# Atribui os argumentos a variáveis
diretorio_inicial="$1"
string_a_procurar="$2"

# Função para percorrer recursivamente os diretórios
search_string() {
    for file in "$1"/*; do
        if [ -d "$file" ]; then
            # Se for um diretório, chama a função recursivamente
            search_string "$file"
        elif [ -f "$file" ]; then
            # Se for um arquivo, verifica se a string está presente
            echo "Verificando arquivo: $file"
            if grep -q "$string_a_procurar" "$file"; then
                echo "String encontrada em: $file"
                echo "$file" >> resultados.txt
            fi
        fi
    done
}

# Nome do arquivo para salvar os resultados
arquivo_resultados="resultados.txt"

# Cria um novo arquivo de resultados ou limpa o existente
> "$arquivo_resultados"

# Chama a função com o diretório inicial
search_string "$diretorio_inicial"

echo "Busca concluída. Resultados salvos em: $arquivo_resultados"
