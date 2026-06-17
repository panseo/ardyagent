<?php
// -----------------------------------------------------------
// ARDY LAB — Sanitizzazione output del modello (rete di sicurezza)
//
// Sole, soprattutto sul ramo WhatsApp dove NON ha tool collegati, ogni
// tanto "recita" la sintassi di una chiamata a tool come fosse testo
// normale (es. `</invoke></function_calls>`). Quel testo non viene mai
// eseguito: finisce pari pari nel messaggio al cliente.
//
// Un'istruzione in prosa nel system prompt ("non scrivere mai sintassi
// tipo <function_calls>") aiuta ma NON è una garanzia tecnica. Questa
// funzione è la garanzia: prima di inviare/salvare qualunque risposta,
// rimuove i residui di sintassi tool dal testo. Logica volutamente
// conservativa: tocca solo token chiaramente sintattici (tag XML del
// vocabolario tool-use), mai parole normali.
// -----------------------------------------------------------

if (!function_exists('ardy_strip_tool_syntax')) {

    /**
     * Rimuove dai testi qualunque residuo di sintassi tool-call lasciato
     * dal modello (blocchi <function_calls>…</function_calls> completi e
     * tag orfani: <invoke>, <parameter>, <tool_use>, ecc.).
     *
     * @param string $text Testo grezzo prodotto dal modello.
     * @return string Testo ripulito, pronto per l'invio al cliente.
     */
    function ardy_strip_tool_syntax(string $text): string
    {
        if ($text === '') return '';

        // Nomi di tag del vocabolario tool-use (con o senza namespace antml:).
        $tags = 'function_calls|invoke|parameter|tool_use|tool_call|tool_result|tool_calls';

        // 1) Rimuovi i blocchi COMPLETI <function_calls>…</function_calls>,
        //    contenuto incluso (a volte il modello sbroda l'intera chiamata).
        $text = preg_replace(
            '#<\s*(?:antml:)?function_calls\b[^>]*>.*?<\s*/\s*(?:antml:)?function_calls\s*>#is',
            '',
            $text
        );

        // 2) Rimuovi i tag ORFANI rimasti (apertura o chiusura), come il
        //    classico `</invoke></function_calls>` senza blocco completo.
        $text = preg_replace(
            '#<\s*/?\s*(?:antml:)?(?:' . $tags . ')\b[^>]*>#i',
            '',
            $text
        );

        // 3) Normalizza la spaziatura lasciata dai tagli: niente run di 3+
        //    righe vuote, niente spazi a fine riga, trim complessivo.
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
