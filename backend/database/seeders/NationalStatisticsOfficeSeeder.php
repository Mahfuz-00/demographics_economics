<?php

namespace Database\Seeders;

use App\Models\Entity;
use App\Models\NationalStatisticsOffice;
use Illuminate\Database\Seeder;

/**
 * Registry of national statistical authorities. These outrank every other source
 * when values disagree, so the registry is what makes the ranking real rather than
 * theoretical.
 *
 * Seeded for the offices with clear official domains. It is deliberately not
 * padded out with guesses: extend it from the admin UI as countries are onboarded.
 */
class NationalStatisticsOfficeSeeder extends Seeder
{
    public function run(): void
    {
        $offices = [
            'USA' => ['U.S. Census Bureau', 'https://www.census.gov/', 'https://data.census.gov/'],
            'GBR' => ['Office for National Statistics', 'https://www.ons.gov.uk/', 'https://www.ons.gov.uk/peoplepopulationandcommunity'],
            'DEU' => ['Statistisches Bundesamt (Destatis)', 'https://www.destatis.de/', 'https://www-genesis.destatis.de/'],
            'FRA' => ['Institut national de la statistique et des études économiques (INSEE)', 'https://www.insee.fr/', 'https://www.insee.fr/fr/statistiques'],
            'ITA' => ['Istituto Nazionale di Statistica (Istat)', 'https://www.istat.it/', 'https://demo.istat.it/'],
            'ESP' => ['Instituto Nacional de Estadística (INE)', 'https://www.ine.es/', 'https://www.ine.es/dyngs/INEbase/'],
            'CAN' => ['Statistics Canada', 'https://www.statcan.gc.ca/', 'https://www150.statcan.gc.ca/'],
            'JPN' => ['Statistics Bureau of Japan', 'https://www.stat.go.jp/english/', 'https://www.e-stat.go.jp/en'],
            'CHN' => ['National Bureau of Statistics of China', 'https://www.stats.gov.cn/english/', null],
            'IND' => ['Ministry of Statistics and Programme Implementation', 'https://www.mospi.gov.in/', 'https://censusindia.gov.in/'],
            'BRA' => ['Instituto Brasileiro de Geografia e Estatística (IBGE)', 'https://www.ibge.gov.br/', 'https://sidra.ibge.gov.br/'],
            'RUS' => ['Federal State Statistics Service (Rosstat)', 'https://rosstat.gov.ru/', null],
            'AUS' => ['Australian Bureau of Statistics', 'https://www.abs.gov.au/', 'https://www.abs.gov.au/statistics'],
            'NZL' => ['Stats NZ', 'https://www.stats.govt.nz/', 'https://www.stats.govt.nz/tools-and-resources/'],
            'KOR' => ['Statistics Korea (KOSTAT)', 'https://kostat.go.kr/', 'https://kosis.kr/eng/'],
            'MEX' => ['Instituto Nacional de Estadística y Geografía (INEGI)', 'https://www.inegi.org.mx/', 'https://www.inegi.org.mx/datos/'],
            'NLD' => ['Centraal Bureau voor de Statistiek (CBS)', 'https://www.cbs.nl/', 'https://opendata.cbs.nl/'],
            'SWE' => ['Statistics Sweden (SCB)', 'https://www.scb.se/', 'https://www.statistikdatabasen.scb.se/'],
            'NOR' => ['Statistics Norway (SSB)', 'https://www.ssb.no/', 'https://www.ssb.no/en/statbank'],
            'DNK' => ['Statistics Denmark', 'https://www.dst.dk/', 'https://www.statbank.dk/'],
            'FIN' => ['Statistics Finland', 'https://www.stat.fi/', 'https://pxdata.stat.fi/'],
            'ISL' => ['Statistics Iceland', 'https://www.statice.is/', null],
            'IRL' => ['Central Statistics Office', 'https://www.cso.ie/', 'https://data.cso.ie/'],
            'POL' => ['Statistics Poland (GUS)', 'https://stat.gov.pl/', 'https://stat.gov.pl/en/'],
            'PRT' => ['Statistics Portugal (INE)', 'https://www.ine.pt/', null],
            'GRC' => ['Hellenic Statistical Authority (ELSTAT)', 'https://www.statistics.gr/', null],
            'TUR' => ['Turkish Statistical Institute (TÜİK)', 'https://www.tuik.gov.tr/', null],
            'CHE' => ['Federal Statistical Office', 'https://www.bfs.admin.ch/', null],
            'AUT' => ['Statistik Austria', 'https://www.statistik.at/', null],
            'BEL' => ['Statbel', 'https://statbel.fgov.be/', null],
            'CZE' => ['Czech Statistical Office', 'https://www.czso.cz/', null],
            'HUN' => ['Hungarian Central Statistical Office', 'https://www.ksh.hu/', null],
            'ROU' => ['National Institute of Statistics', 'https://insse.ro/', null],
            'BGR' => ['National Statistical Institute', 'https://www.nsi.bg/', null],
            'HRV' => ['Croatian Bureau of Statistics', 'https://www.dzs.hr/', null],
            'SVK' => ['Statistical Office of the Slovak Republic', 'https://slovak.statistics.sk/', null],
            'SVN' => ['Statistical Office of the Republic of Slovenia', 'https://www.stat.si/', null],
            'UKR' => ['State Statistics Service of Ukraine', 'https://www.ukrstat.gov.ua/', null],
            'ZAF' => ['Statistics South Africa', 'https://www.statssa.gov.za/', null],
            'NGA' => ['National Bureau of Statistics', 'https://nigerianstat.gov.ng/', null],
            'EGY' => ['Central Agency for Public Mobilization and Statistics (CAPMAS)', 'https://www.capmas.gov.eg/', null],
            'KEN' => ['Kenya National Bureau of Statistics', 'https://www.knbs.or.ke/', null],
            'ETH' => ['Ethiopian Statistical Service', 'https://www.statsethiopia.gov.et/', null],
            'MAR' => ['Haut-Commissariat au Plan', 'https://www.hcp.ma/', null],
            'DZA' => ['Office National des Statistiques', 'https://www.ons.dz/', null],
            'IRN' => ['Statistical Centre of Iran', 'https://www.amar.org.ir/', null],
            'SAU' => ['General Authority for Statistics (GASTAT)', 'https://www.stats.gov.sa/', null],
            'ARE' => ['Federal Competitiveness and Statistics Centre', 'https://www.fcsc.gov.ae/', null],
            'ISR' => ['Central Bureau of Statistics', 'https://www.cbs.gov.il/', null],
            'PAK' => ['Pakistan Bureau of Statistics', 'https://www.pbs.gov.pk/', null],
            'BGD' => ['Bangladesh Bureau of Statistics', 'https://bbs.gov.bd/', null],
            'VNM' => ['General Statistics Office of Vietnam', 'https://www.gso.gov.vn/', null],
            'THA' => ['National Statistical Office of Thailand', 'https://www.nso.go.th/', null],
            'MYS' => ['Department of Statistics Malaysia', 'https://www.dosm.gov.my/', null],
            'PHL' => ['Philippine Statistics Authority', 'https://psa.gov.ph/', null],
            'SGP' => ['Singapore Department of Statistics', 'https://www.singstat.gov.sg/', null],
            'IDN' => ['Badan Pusat Statistik (BPS)', 'https://www.bps.go.id/', null],
            'ARG' => ['Instituto Nacional de Estadística y Censos (INDEC)', 'https://www.indec.gob.ar/', null],
            'CHL' => ['Instituto Nacional de Estadísticas', 'https://www.ine.cl/', null],
            'COL' => ['Departamento Administrativo Nacional de Estadística (DANE)', 'https://www.dane.gov.co/', null],
            'PER' => ['Instituto Nacional de Estadística e Informática (INEI)', 'https://www.inei.gob.pe/', null],
        ];

        $entityIds = Entity::query()
            ->whereNotNull('iso_alpha3')
            ->pluck('id', 'iso_alpha3');

        foreach ($offices as $alpha3 => [$name, $website, $portal]) {
            $entityId = $entityIds->get($alpha3);

            if (! $entityId) {
                continue;
            }

            NationalStatisticsOffice::updateOrCreate(
                ['entity_id' => $entityId, 'office_name' => $name],
                [
                    'website_url' => $website,
                    'data_portal_url' => $portal,
                    'has_api' => false,
                    'is_primary' => true,
                    'access_notes' => 'Registered for source prioritisation. Verify API or machine-readable data availability before wiring automated ingestion.',
                ]
            );
        }
    }
}
