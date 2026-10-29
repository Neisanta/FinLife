<?php
/**
 * FinLife — Universo de ativos (curadoria real, classes finas).
 * Serve para painéis (home, movers, heatmap, comparador) e para dar
 * nome/segmento aos símbolos. Todas as cotações vêm dos provedores —
 * aqui ficam apenas identificadores e metadados factuais.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class MarketUniverse
{
    /** Home do mercado (tarja principal). */
    public const HOME = [
        ['symbol' => '^BVSP', 'label' => 'Ibovespa', 'class' => 'index', 'decimals' => 0],
        ['symbol' => '^GSPC', 'label' => 'S&P 500', 'class' => 'index', 'decimals' => 2],
        ['symbol' => '^IXIC', 'label' => 'Nasdaq', 'class' => 'index', 'decimals' => 2],
        ['symbol' => 'USDBRL', 'label' => 'Dólar', 'class' => 'forex', 'decimals' => 4],
        ['symbol' => 'BTCUSDT', 'label' => 'Bitcoin', 'class' => 'cripto', 'decimals' => 2],
        ['symbol' => 'ETHUSDT', 'label' => 'Ethereum', 'class' => 'cripto', 'decimals' => 2],
    ];

    public const INDICES = [
        ['symbol' => '^BVSP', 'label' => 'Ibovespa', 'class' => 'index', 'decimals' => 0],
        ['symbol' => '^IFIX', 'label' => 'IFIX (FIIs)', 'class' => 'index', 'decimals' => 2],
        ['symbol' => '^SMLL', 'label' => 'SMLL (Small Caps)', 'class' => 'index', 'decimals' => 0],
        ['symbol' => '^GSPC', 'label' => 'S&P 500', 'class' => 'index', 'decimals' => 2],
        ['symbol' => '^IXIC', 'label' => 'Nasdaq Composite', 'class' => 'index', 'decimals' => 2],
        ['symbol' => '^DJI', 'label' => 'Dow Jones', 'class' => 'index', 'decimals' => 2],
        ['symbol' => '^RUT', 'label' => 'Russell 2000', 'class' => 'index', 'decimals' => 2],
        ['symbol' => '^VIX', 'label' => 'VIX (volatilidade)', 'class' => 'index', 'decimals' => 2],
        ['symbol' => '^GDAXI', 'label' => 'DAX (Alemanha)', 'class' => 'index', 'decimals' => 2],
        ['symbol' => '^FTSE', 'label' => 'FTSE 100 (Reino Unido)', 'class' => 'index', 'decimals' => 2],
        ['symbol' => '^N225', 'label' => 'Nikkei 225 (Japão)', 'class' => 'index', 'decimals' => 2],
        ['symbol' => '^HSI', 'label' => 'Hang Seng (Hong Kong)', 'class' => 'index', 'decimals' => 2],
    ];

    public const FOREX = [
        ['symbol' => 'USDBRL', 'label' => 'USD/BRL', 'class' => 'forex', 'decimals' => 4],
        ['symbol' => 'EURBRL', 'label' => 'EUR/BRL', 'class' => 'forex', 'decimals' => 4],
        ['symbol' => 'GBPBRL', 'label' => 'GBP/BRL', 'class' => 'forex', 'decimals' => 4],
        ['symbol' => 'JPYBRL', 'label' => 'JPY/BRL', 'class' => 'forex', 'decimals' => 4],
        ['symbol' => 'EURUSD=X', 'label' => 'EUR/USD', 'class' => 'forex', 'decimals' => 4],
    ];

    /** Futures de referência — preço spot/futuro conforme a fonte. */
    public const COMMODITIES = [
        ['symbol' => 'GC=F', 'label' => 'Ouro (futuro)', 'class' => 'commodity', 'decimals' => 2],
        ['symbol' => 'SI=F', 'label' => 'Prata (futuro)', 'class' => 'commodity', 'decimals' => 3],
        ['symbol' => 'BZ=F', 'label' => 'Petróleo Brent (futuro)', 'class' => 'commodity', 'decimals' => 2],
        ['symbol' => 'CL=F', 'label' => 'Petróleo WTI (futuro)', 'class' => 'commodity', 'decimals' => 2],
        ['symbol' => 'ZC=F', 'label' => 'Milho (futuro)', 'class' => 'commodity', 'decimals' => 2],
        ['symbol' => 'ZS=F', 'label' => 'Soja (futuro)', 'class' => 'commodity', 'decimals' => 2],
        ['symbol' => 'KC=F', 'label' => 'Café (futuro)', 'class' => 'commodity', 'decimals' => 2],
    ];

    public const BR_STOCKS = [
        ['symbol' => 'PETR4.SA', 'label' => 'Petrobras PN', 'class' => 'br_stock', 'sector' => 'Petróleo e Gás', 'decimals' => 2],
        ['symbol' => 'PETR3.SA', 'label' => 'Petrobras ON', 'class' => 'br_stock', 'sector' => 'Petróleo e Gás', 'decimals' => 2],
        ['symbol' => 'VALE3.SA', 'label' => 'Vale ON', 'class' => 'br_stock', 'sector' => 'Mineração', 'decimals' => 2],
        ['symbol' => 'ITUB4.SA', 'label' => 'Itaú Unibanco PN', 'class' => 'br_stock', 'sector' => 'Bancos', 'decimals' => 2],
        ['symbol' => 'BBAS3.SA', 'label' => 'Banco do Brasil ON', 'class' => 'br_stock', 'sector' => 'Bancos', 'decimals' => 2],
        ['symbol' => 'BBDC4.SA', 'label' => 'Bradesco PN', 'class' => 'br_stock', 'sector' => 'Bancos', 'decimals' => 2],
        ['symbol' => 'SANB11.SA', 'label' => 'Santander Brasil UNT', 'class' => 'br_stock', 'sector' => 'Bancos', 'decimals' => 2],
        ['symbol' => 'ABEV3.SA', 'label' => 'Ambev ON', 'class' => 'br_stock', 'sector' => 'Bebidas', 'decimals' => 2],
        ['symbol' => 'WEGE3.SA', 'label' => 'WEG ON', 'class' => 'br_stock', 'sector' => 'Bens de Capital', 'decimals' => 2],
        ['symbol' => 'RENT3.SA', 'label' => 'Localiza ON', 'class' => 'br_stock', 'sector' => 'Aluguel de Veículos', 'decimals' => 2],
        ['symbol' => 'SUZB3.SA', 'label' => 'Suzano ON', 'class' => 'br_stock', 'sector' => 'Papel e Celulose', 'decimals' => 2],
        ['symbol' => 'PRIO3.SA', 'label' => 'PetroRio ON', 'class' => 'br_stock', 'sector' => 'Petróleo e Gás', 'decimals' => 2],
        ['symbol' => 'B3SA3.SA', 'label' => 'B3 ON', 'class' => 'br_stock', 'sector' => 'Serviços Financeiros', 'decimals' => 2],
        ['symbol' => 'EMBR3.SA', 'label' => 'Embraer ON', 'class' => 'br_stock', 'sector' => 'Aeroespacial', 'decimals' => 2],
        ['symbol' => 'MGLU3.SA', 'label' => 'Magazine Luiza ON', 'class' => 'br_stock', 'sector' => 'Varejo', 'decimals' => 2],
        ['symbol' => 'RADL3.SA', 'label' => 'Raia Drogasil ON', 'class' => 'br_stock', 'sector' => 'Saúde/Varejo', 'decimals' => 2],
        ['symbol' => 'EQTL3.SA', 'label' => 'Equatorial ON', 'class' => 'br_stock', 'sector' => 'Energia', 'decimals' => 2],
        ['symbol' => 'CSAN3.SA', 'label' => 'Cosan ON', 'class' => 'br_stock', 'sector' => 'Conglomerado', 'decimals' => 2],
        ['symbol' => 'HAPV3.SA', 'label' => 'Hapvida ON', 'class' => 'br_stock', 'sector' => 'Saúde', 'decimals' => 2],
        ['symbol' => 'LREN4.SA', 'label' => 'Lojas Renner PN', 'class' => 'br_stock', 'sector' => 'Varejo', 'decimals' => 2],
        ['symbol' => 'GGBR4.SA', 'label' => 'Gerdau PN', 'class' => 'br_stock', 'sector' => 'Siderurgia', 'decimals' => 2],
        ['symbol' => 'CSNA3.SA', 'label' => 'Companhia Siderúrgica Nacional ON', 'class' => 'br_stock', 'sector' => 'Siderurgia', 'decimals' => 2],
        ['symbol' => 'USIM5.SA', 'label' => 'Usiminas PNA', 'class' => 'br_stock', 'sector' => 'Siderurgia', 'decimals' => 2],
        ['symbol' => 'TOTS3.SA', 'label' => 'Totvs ON', 'class' => 'br_stock', 'sector' => 'Tecnologia', 'decimals' => 2],
        ['symbol' => 'FLRY3.SA', 'label' => 'Fleury ON', 'class' => 'br_stock', 'sector' => 'Saúde', 'decimals' => 2],
        ['symbol' => 'QUAL3.SA', 'label' => 'Qualicorp ON', 'class' => 'br_stock', 'sector' => 'Saúde', 'decimals' => 2],
        ['symbol' => 'BBSE3.SA', 'label' => 'BB Seguridade ON', 'class' => 'br_stock', 'sector' => 'Seguros', 'decimals' => 2],
        ['symbol' => 'SBSP3.SA', 'label' => 'Sabesp ON', 'class' => 'br_stock', 'sector' => 'Saneamento', 'decimals' => 2],
        ['symbol' => 'CMIG4.SA', 'label' => 'Cemig PN', 'class' => 'br_stock', 'sector' => 'Energia', 'decimals' => 2],
        ['symbol' => 'EGIE3.SA', 'label' => 'Engie ON', 'class' => 'br_stock', 'sector' => 'Energia', 'decimals' => 2],
        ['symbol' => 'ELET3.SA', 'label' => 'Eletrobras ON', 'class' => 'br_stock', 'sector' => 'Energia', 'decimals' => 2],
        ['symbol' => 'RAIL3.SA', 'label' => 'Rumo ON', 'class' => 'br_stock', 'sector' => 'Logística', 'decimals' => 2],
        ['symbol' => 'KLBN11.SA', 'label' => 'Klabin UNT', 'class' => 'br_stock', 'sector' => 'Papel e Celulose', 'decimals' => 2],
        ['symbol' => 'UGPA3.SA', 'label' => 'Ultrapar ON', 'class' => 'br_stock', 'sector' => 'Conglomerado', 'decimals' => 2],
        ['symbol' => 'VIVT3.SA', 'label' => 'Telefônica Brasil ON', 'class' => 'br_stock', 'sector' => 'Telecom', 'decimals' => 2],
        ['symbol' => 'TIMS3.SA', 'label' => 'TIM ON', 'class' => 'br_stock', 'sector' => 'Telecom', 'decimals' => 2],
        ['symbol' => 'BRKM5.SA', 'label' => 'Braskem PNA', 'class' => 'br_stock', 'sector' => 'Química', 'decimals' => 2],
    ];

    public const FIIS = [
        ['symbol' => 'HGLG11.SA', 'label' => 'CSHG Logística HGLG11', 'class' => 'fii', 'segment' => 'Logística', 'decimals' => 2],
        ['symbol' => 'MXRF11.SA', 'label' => 'Maxi Renda MXRF11', 'class' => 'fii', 'segment' => 'Papel', 'decimals' => 2],
        ['symbol' => 'XPML11.SA', 'label' => 'XP Malls XPML11', 'class' => 'fii', 'segment' => 'Shoppings', 'decimals' => 2],
        ['symbol' => 'BCFF11.SA', 'label' => 'FII BC Fund BCFF11', 'class' => 'fii', 'segment' => 'Recebíveis', 'decimals' => 2],
        ['symbol' => 'KNCR11.SA', 'label' => 'Kinea Renda KNCR11', 'class' => 'fii', 'segment' => 'Recebíveis', 'decimals' => 2],
        ['symbol' => 'IRDM11.SA', 'label' => 'Iridium Recebíveis IRDM11', 'class' => 'fii', 'segment' => 'Recebíveis', 'decimals' => 2],
        ['symbol' => 'BRCR11.SA', 'label' => 'BC Fund BRCR11', 'class' => 'fii', 'segment' => 'Lajes Corporativas', 'decimals' => 2],
        ['symbol' => 'GGRC11.SA', 'label' => 'GGR Criciúma GGRC11', 'class' => 'fii', 'segment' => 'Shoppings', 'decimals' => 2],
        ['symbol' => 'HGBS11.SA', 'label' => 'CSHG Brasil HGBS11', 'class' => 'fii', 'segment' => 'Híbrido', 'decimals' => 2],
        ['symbol' => 'HFOF11.SA', 'label' => 'Deutsche Hedge HFOF11', 'class' => 'fii', 'segment' => 'Fundo de Fundos', 'decimals' => 2],
    ];

    public const FIAGROS = [
        ['symbol' => 'RZAG11.SA', 'label' => 'Riza Agro RZAG11', 'class' => 'fiagro', 'segment' => 'Agro', 'decimals' => 2],
        ['symbol' => 'KNCA11.SA', 'label' => 'Kinea Agro KNCA11', 'class' => 'fiagro', 'segment' => 'Agro', 'decimals' => 2],
        ['symbol' => 'HTMX11.SA', 'label' => 'Iridium Agro HTMX11', 'class' => 'fiagro', 'segment' => 'Agro', 'decimals' => 2],
        ['symbol' => 'BNAV11.SA', 'label' => 'Bona Agro BNAV11', 'class' => 'fiagro', 'segment' => 'Agro', 'decimals' => 2],
        ['symbol' => 'SDIL11.SA', 'label' => 'Stratus Agro SDIL11', 'class' => 'fiagro', 'segment' => 'Agro', 'decimals' => 2],
    ];

    public const ETFS = [
        ['symbol' => 'BOVA11.SA', 'label' => 'iShares Ibovespa BOVA11', 'class' => 'etf', 'benchmark' => 'Ibovespa', 'decimals' => 2],
        ['symbol' => 'IVVB11.SA', 'label' => 'iShares S&P 500 IVVB11', 'class' => 'etf', 'benchmark' => 'S&P 500', 'decimals' => 2],
        ['symbol' => 'SMAL11.SA', 'label' => 'iShares Small Cap SMAL11', 'class' => 'etf', 'benchmark' => 'SMLL', 'decimals' => 2],
        ['symbol' => 'DIVO11.SA', 'label' => 'It NOW Dividendos DIVO11', 'class' => 'etf', 'benchmark' => 'IDIV', 'decimals' => 2],
        ['symbol' => 'HASH11.SA', 'label' => 'Hashdex NASDAQ HASH11', 'class' => 'etf', 'benchmark' => 'Nasdaq-100', 'decimals' => 2],
        ['symbol' => 'IMOB11.SA', 'label' => 'iShares Imobiliário IMOB11', 'class' => 'etf', 'benchmark' => 'IFIX', 'decimals' => 2],
        ['symbol' => 'BOVV11.SA', 'label' => 'It NOW Ibovespa BOVV11', 'class' => 'etf', 'benchmark' => 'Ibovespa', 'decimals' => 2],
        ['symbol' => 'PIBB11.SA', 'label' => 'It NOW PIBB PIBB11', 'class' => 'etf', 'benchmark' => 'IBrX', 'decimals' => 2],
    ];

    /** BDRs — disponibilidade varia na fonte; os ausentes são omitidos com aviso. */
    public const BDRS = [
        ['symbol' => 'AAPB34.SA', 'label' => 'BDR Apple', 'class' => 'bdr', 'country' => 'EUA', 'decimals' => 2],
        ['symbol' => 'MSFT34.SA', 'label' => 'BDR Microsoft', 'class' => 'bdr', 'country' => 'EUA', 'decimals' => 2],
        ['symbol' => 'GOGL34.SA', 'label' => 'BDR Alphabet', 'class' => 'bdr', 'country' => 'EUA', 'decimals' => 2],
        ['symbol' => 'AMZO34.SA', 'label' => 'BDR Amazon', 'class' => 'bdr', 'country' => 'EUA', 'decimals' => 2],
        ['symbol' => 'TSLA34.SA', 'label' => 'BDR Tesla', 'class' => 'bdr', 'country' => 'EUA', 'decimals' => 2],
        ['symbol' => 'META34.SA', 'label' => 'BDR Meta', 'class' => 'bdr', 'country' => 'EUA', 'decimals' => 2],
    ];

    public const CRYPTO = [
        ['symbol' => 'BTCUSDT', 'label' => 'Bitcoin', 'class' => 'cripto', 'decimals' => 2],
        ['symbol' => 'ETHUSDT', 'label' => 'Ethereum', 'class' => 'cripto', 'decimals' => 2],
        ['symbol' => 'SOLUSDT', 'label' => 'Solana', 'class' => 'cripto', 'decimals' => 2],
        ['symbol' => 'XRPUSDT', 'label' => 'XRP', 'class' => 'cripto', 'decimals' => 4],
        ['symbol' => 'BNBUSDT', 'label' => 'BNB', 'class' => 'cripto', 'decimals' => 2],
        ['symbol' => 'ADAUSDT', 'label' => 'Cardano', 'class' => 'cripto', 'decimals' => 4],
        ['symbol' => 'DOGEUSDT', 'label' => 'Dogecoin', 'class' => 'cripto', 'decimals' => 4],
        ['symbol' => 'USDTUSDT', 'label' => 'Tether', 'class' => 'cripto', 'decimals' => 4],
        ['symbol' => 'USDCUSDT', 'label' => 'USD Coin', 'class' => 'cripto', 'decimals' => 4],
    ];

    public const CLASS_LABELS = [
        'br_stock' => 'Ações B3', 'fii' => 'FIIs', 'fiagro' => 'FIAGROs', 'etf' => 'ETFs',
        'bdr' => 'BDRs', 'index' => 'Índices', 'cripto' => 'Criptomoedas',
        'forex' => 'Câmbio', 'commodity' => 'Commodities', 'us_stock' => 'Ações Internacionais',
    ];

    /** Todas as listas por chave de classe. */
    public static function all(): array
    {
        return [
            'br_stock' => self::BR_STOCKS,
            'fii' => self::FIIS,
            'fiagro' => self::FIAGROS,
            'etf' => self::ETFS,
            'bdr' => self::BDRS,
            'index' => self::INDICES,
            'cripto' => self::CRYPTO,
            'forex' => self::FOREX,
            'commodity' => self::COMMODITIES,
        ];
    }

    /** Metadados de um símbolo do universo (label/classe/decimals). */
    public static function meta(string $symbol): ?array
    {
        foreach (self::all() as $list) {
            foreach ($list as $item) {
                if ($item['symbol'] === $symbol) {
                    return $item;
                }
            }
        }
        return null;
    }

    public static function label(string $symbol): string
    {
        $m = self::meta($symbol);
        return $m['label'] ?? $symbol;
    }

    public static function decimals(string $symbol): int
    {
        $m = self::meta($symbol);
        return $m['decimals'] ?? 2;
    }

    /** Normaliza input do usuário → símbolo canônico do sistema. */
    public static function normalize(string $input): string
    {
        $s = strtoupper(trim($input));
        if ($s === '') {
            return '';
        }
        // forex BRL: "USD-BRL" ou "USDBRL" → canônico "USDBRL"
        if (preg_match('~^([A-Z]{3})-BRL$~', $s, $m) === 1) {
            return $m[1] . 'BRL';
        }
        if (str_ends_with($s, '.SA') || str_starts_with($s, '^') || str_contains($s, '=') || str_contains($s, '-')) {
            return $s;
        }
        // cripto com par explícito
        foreach (['USDT', 'USDC', 'FDUSD', 'BUSD'] as $q) {
            if (str_ends_with($s, $q) && strlen($s) > strlen($q)) {
                return $s;
            }
        }
        // cripto base conhecida (BTC → BTCUSDT)
        foreach (self::CRYPTO as $c) {
            if ($c['symbol'] === $s . 'USDT') {
                return $c['symbol'];
            }
        }
        if (preg_match('~^([A-Z]{3})BRL$~', $s) === 1) {
            return $s;
        }
        // ticker B3: 5-6 letras terminando em dígito (PETR4, HGLG11, IVVB11, AAPB34)
        if (preg_match('~^[A-Z]{4,5}\d{1,2}$~', $s) === 1) {
            return $s . '.SA';
        }
        return $s;
    }

    /** Símbolo para exibição (PETR4.SA → PETR4; BTCUSDT → BTC/USDT). */
    public static function display(string $symbol): string
    {
        if (str_ends_with($symbol, '.SA')) {
            return substr($symbol, 0, -3);
        }
        foreach (['USDT', 'USDC', 'FDUSD', 'BUSD'] as $q) {
            if (str_ends_with($symbol, $q) && strlen($symbol) > strlen($q)) {
                return substr($symbol, 0, -strlen($q)) . '/' . $q;
            }
        }
        if (preg_match('~^([A-Z]{3})BRL$~', $symbol, $m) === 1) {
            return $m[1] . '/BRL';
        }
        return $symbol;
    }
}
