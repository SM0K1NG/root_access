#!/bin/bash

# Verifica se o número de argumentos é correto
if [ "$#" -lt 2 ]; then
    echo "Uso: $0 <caminho_do_diretorio> <string_a_procurar> [extensao1 extensao2 ...]"
    exit 1
fi

# Atribui os argumentos a variáveis
diretorio_inicial="$1"
string_a_procurar="$2"

# Obtem a lista de extensoes
shift 2
extensoes=("$@")

# Se nenhum argumento de extensao for fornecido, considera todas as extensoes
if [ ${#extensoes[@]} -eq 0 ] || [ "${extensoes[0]}" = "*" ]; then
    extensoes=(".*")
fi

# Nome do arquivo para salvar os resultados
arquivo_resultados="resultados.txt"

# Cores ANSI
verde="\033[0;32m"
vermelho="\033[0;31m"
reset="\033[0m"

# Função para percorrer recursivamente os diretórios
search_string() {
    for file in "$1"/*; do
        if [ -d "$file" ]; then
            # Se for um diretório, chama a função recursivamente
            search_string "$file"
        elif [ -f "$file" ]; then
            # Verifica se a extensão do arquivo está na lista permitida
            for ext in "${extensoes[@]}"; do
                if [[ "$file" =~ $ext ]]; then
                    # Se for um arquivo permitido, verifica se a string está presente
                    echo -n "Verificando arquivo: $file"
                    if grep -q "$string_a_procurar" "$file"; then
                        echo -e " - ${verde}String encontrada${reset}"
                        echo "$file" >> "$arquivo_resultados"
                    else
                        echo -e " - ${vermelho}String não encontrada${reset}"
                    fi
                    break
                fi
            done
        fi
    done
}

# Cria um novo arquivo de resultados ou limpa o existente
> "$arquivo_resultados"

# Chama a função com o diretório inicial
search_string "$diretorio_inicial"

echo "Busca concluída. Resultados salvos em: $arquivo_resultados"
