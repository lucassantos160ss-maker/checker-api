$resposta_json = json_decode($resposta_bruta, true);
    if (is_array($resposta_json)) {
        $status_site = $resposta_json['status'] ?? 'failed';
        $retorno_msg = $resposta_json['message'] ?? $resposta_json['error'] ?? 'Retorno sem mensagem';
    } else {
        $status_site = 'failed';
        
        // Tenta capturar a tag <title> do HTML de bloqueio (ex: "Just a moment...", "403 Forbidden")
        preg_match('/<title>(.*?)<\/title>/is', $resposta_bruta, $matches);
        $titulo_erro = $matches[1] ?? 'Sem título';
        
        // Pega os primeiros 60 caracteres do texto puro da página para identificar o firewall
        $texto_limpo = preg_replace('/\s+/', ' ', strip_tags($resposta_bruta));
        $resumo_erro = substr(trim($texto_limpo), 0, 60);
        
        $retorno_msg = "WAF: [{$titulo_erro}] - {$resumo_erro}...";
    }
