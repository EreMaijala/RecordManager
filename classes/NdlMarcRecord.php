<?php
/**
 * NdlMarcRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2012-2015.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
require_once 'MarcRecord.php';
require_once 'MetadataUtils.php';

/**
 * NdlMarcRecord Class
 *
 * MarcRecord with NDL specific functionality
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class NdlMarcRecord extends MarcRecord
{
    /**
     * Normalize the record (optional)
     *
     * @return void
     */
    public function normalize()
    {
        if (isset($this->fields['653'])
            && strncmp($this->source, 'metalib', 7) == 0
        ) {
            // Split MetaLib subjects
            $fields = [];
            foreach ($this->fields['653'] as &$field) {
                foreach ($field['s'] as $subfield) {
                    if (key($subfield) == 'a') {
                        $values = preg_split('/[;\,]\s+/', current($subfield));
                        foreach ($values as $value) {
                            $fields[] = [
                                'i1' => $field['i1'],
                                'i2' => $field['i2'],
                                's' => [['a' => $value]]
                            ];
                        }
                    } else {
                        $fields[] = [
                            'i1' => $field['i1'],
                            'i2' => $field['i2'],
                            's' => [$subfield]
                        ];
                    }
                }
            }
            $this->fields['653'] = $fields;
        }
        // Kyyti enumeration from 362 to title
        if ($this->source == 'kyyti' && isset($this->fields['245'])
            && isset($this->fields['362'])
        ) {
            $enum = $this->getFieldSubfields('362', ['a' => 1]);
            if ($enum) {
                $this->fields['245'][0]['s'][] = ['n' => $enum];
            }
        }

    }

    /**
     * Return record linking ID (typically same as ID) used for links
     * between records in the data source
     *
     * @return string
     */
    public function getLinkingID()
    {
        if ($this->getDriverParam('003InLinkingID', false)) {
            $source = $this->getField('003');
            if ($source) {
                return "($source)" . $this->getID();
            }
        }
        return $this->getID();
    }

    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return string[]
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        if (isset($data['publishDate'])) {
            $data['main_date_str']
                = MetadataUtils::extractYear($data['publishDate'][0]);
            $data['main_date']
                = $this->validateDate($data['main_date_str'] . '-01-01T00:00:00Z');
        }
        if ($range = $this->getPublicationDateRange()) {
            $data['search_sdaterange_mv'][] = $data['publication_sdaterange']
                = MetadataUtils::dateRangeToNumeric($range);
            $data['search_daterange_mv'][] = $data['publication_daterange']
                = MetadataUtils::dateRangeToStr($range);
        }
        $data['publication_place_txt_mv'] = MetadataUtils::arrayTrim(
            $this->getFieldsSubfields(
                [
                    [MarcRecord::GET_NORMAL, '260', ['a' => 1]]
                ]
            ),
            ' []'
        );

        $data['subtitle_lng_str_mv'] = $this->getFieldsSubfields(
            [
                [MarcRecord::GET_NORMAL, '041', ['j' => 1]],
                // 979j = component part subtitle language
                [MarcRecord::GET_NORMAL, '979', ['j' => 1]]
            ],
            false, true, true
        );

        $data['original_lng_str_mv'] = $this->getFieldsSubfields(
            [
                [MarcRecord::GET_NORMAL, '041', ['h' => 1]],
                // 979i = component part original language
                [MarcRecord::GET_NORMAL, '979', ['i' => 1]]
            ],
            false, true, true
        );

        // 979cd = component part authors
        // 900, 910, 911 = Finnish reference field
        foreach ($this->getFieldsSubfields(
            [
                [MarcRecord::GET_BOTH, '979', ['c' => 1]],
                [MarcRecord::GET_BOTH, '979', ['d' => 1]],
                [MarcRecord::GET_BOTH, '900', ['a' => 1]],
                [MarcRecord::GET_BOTH, '910', ['a' => 1, 'b' => 1]],
                [MarcRecord::GET_BOTH, '911', ['a' => 1, 'e' => 1]]
            ],
            false, true, true
        ) as $field) {
            $data['author2'][] = $field;
        }
        $key = array_search($data['author'], $data['author2']);
        if ($key !== false) {
            unset($data['author2'][$key]);
        }
        $data['author2'] = array_filter(array_values($data['author2']));

        $data['title_alt'] = array_values(
            array_unique(
                $this->getFieldsSubfields(
                    [
                        [MarcRecord::GET_ALT, '245', ['a' => 1, 'b' => 1]],
                        [MarcRecord::GET_BOTH, '130', [
                            'a' => 1, 'd' => 1, 'f' => 1, 'g' => 1, 'h' => 1,
                            'k' => 1, 'l' => 1, 'n' => 1, 'p' => 1, 'r' => 1,
                            's' => 1, 't' => 1
                        ]],
                        [MarcRecord::GET_BOTH, '240', [
                            'a' => 1, 'd' => 1, 'f' => 1, 'g' => 1, 'k' => 1,
                            'l' => 1, 'n' => 1, 'p' => 1, 'r' => 1, 's' => 1
                        ]],
                        [MarcRecord::GET_BOTH, '243', [
                            'a' => 1, 'd' => 1, 'f' => 1, 'g' => 1, 'h' => 1,
                            'k' => 1, 'l' => 1, 'm' => 1, 'n' => 1, 'o' => 1,
                            'p' => 1, 'r' => 1, 's' => 1
                        ]],
                        [MarcRecord::GET_BOTH, '246', [
                            'a' => 1, 'b' => 1, 'g' => 1
                        ]],
                        // Use only 700 fields that contain subfield 't'
                        [MarcRecord::GET_BOTH, '700', [
                            't' => 1, 'm' => 1, 'r' => 1, 'h' => 1,
                            'i' => 1, 'g' => 1, 'n' => 1, 'p' => 1, 's' => 1,
                            'l' => 1, 'o' => 1, 'k' => 1],
                            ['t' => 1]
                        ],
                        [MarcRecord::GET_BOTH, '730', [
                            'a' => 1, 'd' => 1, 'f' => 1, 'g' => 1, 'h' => 1,
                            'i' => 1, 'k' => 1, 'l' => 1, 'm' => 1, 'n' => 1,
                            'o' => 1, 'p' => 1, 'r' => 1, 's' => 1, 't' => 1
                        ]],
                        [MarcRecord::GET_BOTH, '740', ['a' => 1]],
                        // 979b = component part title
                        [MarcRecord::GET_BOTH, '979', ['b' => 1]],
                        // 979e = component part uniform title
                        [MarcRecord::GET_BOTH, '979', ['e' => 1]],
                        // Finnish 9xx reference field
                        [MarcRecord::GET_BOTH, '940', ['a' => 1]],
                    ]
                )
            )
        );

        // Location coordinates
        $field = $this->getField('034');
        if ($field) {
            $westOrig = $this->getSubfield($field, 'd');
            $eastOrig = $this->getSubfield($field, 'e');
            $northOrig = $this->getSubfield($field, 'f');
            $southOrig = $this->getSubfield($field, 'g');
            $west = MetadataUtils::coordinateToDecimal($westOrig);
            $east = MetadataUtils::coordinateToDecimal($eastOrig);
            $north = MetadataUtils::coordinateToDecimal($northOrig);
            $south = MetadataUtils::coordinateToDecimal($southOrig);

            if (!is_nan($west) && !is_nan($north)) {
                if (($west < -180 || $west > 180) || ($north < -90 || $north > 90)) {
                    global $logger;
                    $logger->log(
                        'NdlMarcRecord',
                        "Discarding invalid coordinates $west,$north decoded from "
                        . "w=$westOrig, e=$eastOrig, n=$northOrig, s=$southOrig, "
                        . "record {$this->source}." . $this->getID(),
                        Logger::WARNING
                    );
                } else {
                    if (!is_nan($east) && !is_nan($south)) {
                        if ($east < -180 || $east > 180 || $south < -90
                            || $south > 90
                        ) {
                            global $logger;
                            $logger->log(
                                'NdlMarcRecord',
                                "Discarding invalid coordinates $east,$south "
                                . "decoded from w=$westOrig, e=$eastOrig, "
                                . "n=$northOrig, s=$southOrig, record "
                                . "{$this->source}." . $this->getID(),
                                Logger::WARNING
                            );
                        } else {
                            // Try to cope with weird coordinate order
                            if ($north > $south) {
                                list($north, $south) = [$south, $north];
                            }
                            if ($west > $east) {
                                list($west, $east) = [$east, $west];
                            }
                            $data['location_geo']
                                = "ENVELOPE($west, $east, $south, $north)";
                        }
                    } else {
                        $data['location_geo'] = "POINT($west $north)";
                    }
                }
            }
        }

        // Classifications
        foreach ($this->getFields('080') as $field080) {
            $classification = trim($this->getSubfield($field080, 'a'));
            $classification .= trim($this->getSubfield($field080, 'b'));
            if ($classification) {
                $aux = $this->getSubfields($field080, ['x' => 1]);
                if ($aux) {
                    $classification .= " $aux";
                }
                $data['classification_str_mv'][] = "udk $classification";
            }
        }
        $dlc = $this->getFieldsSubfields(
            [[MarcRecord::GET_NORMAL, '050', ['a' => 1, 'b' => 1]]]
        );
        foreach ($dlc as $classification) {
            $data['classification_str_mv'][] = 'dlc '
                . mb_strtolower(str_replace(' ', '', $classification), 'UTF-8');
        }
        foreach ($this->getFields('084') as $field) {
            $source = $this->getSubfield($field, '2');
            $classification = $this->getSubfields($field, ['a' => 1, 'b' => 1]);
            if ($source) {
                $data['classification_str_mv'][] = "$source "
                    . mb_strtolower(str_replace(' ', '', $classification), 'UTF-8');
            }
        }
        if (isset($data['classification_str_mv'])) {
            $data['allfields']
                = array_merge($data['allfields'], $data['classification_str_mv']);
        }

        // Ebrary location
        $ebraryLocs = $this->getFieldsSubfields(
            [[MarcRecord::GET_NORMAL, '035', ['a' => 1]]]
        );
        foreach ($ebraryLocs as $field) {
            if (strncmp($field, 'ebr', 3) == 0 && is_numeric(substr($field, 3))) {
                if (!isset($data['building'])
                    || !in_array('EbraryDynamic', $data['building'])
                ) {
                    $data['building'][] = 'EbraryDynamic';
                }
            }
        }

        // Topics
        if (strncmp($this->source, 'metalib', 7) == 0) {
            $field653 = $this->getFieldsSubfields(
                [[MarcRecord::GET_BOTH, '653', ['a' => 1]]]
            );
            $data['topic'] = array_merge($data['topic'], $field653);
            $data['topic_facet'] = array_merge($data['topic_facet'], $field653);
        }

        // Original Study Number
        $data['ctrlnum'] = array_merge(
            $data['ctrlnum'],
            $this->getFieldsSubfields([[MarcRecord::GET_NORMAL, '036', ['a' => 1]]])
        );

        // Source
        $data['source_str_mv'] = $this->source;
        $data['datasource_str_mv'] = $this->source;

        // ISSN
        $data['issn'] = $this->getFieldsSubfields(
            [[MarcRecord::GET_NORMAL, '022', ['a' => 1]]]
        );
        foreach ($data['issn'] as &$value) {
            $value = str_replace('-', '', $value);
        }
        $data['other_issn_str_mv'] = $this->getFieldsSubfields(
            [
                [MarcRecord::GET_NORMAL, '440', ['x' => 1]],
                [MarcRecord::GET_NORMAL, '480', ['x' => 1]],
                [MarcRecord::GET_NORMAL, '730', ['x' => 1]],
                [MarcRecord::GET_NORMAL, '776', ['x' => 1]]
            ]
        );
        foreach ($data['other_issn_str_mv'] as &$value) {
            $value = str_replace('-', '', $value);
        }
        $data['linking_issn_str_mv'] = $this->getFieldsSubfields(
            [[MarcRecord::GET_NORMAL, '022', ['l' => 1]]]
        );
        foreach ($data['linking_issn_str_mv'] as &$value) {
            $value = str_replace('-', '', $value);
        }

        // URLs
        $fields = $this->getFields('856');
        foreach ($fields as $field) {
            $ind2 = $this->getIndicator($field, 2);
            $sub3 = $this->getSubfield($field, 3);
            if (($ind2 == '0' || $ind2 == '1') && !$sub3) {
                $url = trim($this->getSubfield($field, 'u'));
                if (!$url) {
                    continue;
                }
                // Require at least one dot surrounded by valid characters or a
                // familiar scheme
                if (!preg_match('/[A-Za-z0-9]\.[A-Za-z0-9]/', $url)
                    && !preg_match('/^(http|ftp)s?:\/\//', $url)
                ) {
                    continue;
                }
                $data['online_boolean'] = true;
                $data['online_str_mv'] = $this->source;
                $linkText = $this->getSubfield($field, 'y');
                if (!$linkText) {
                    $linkText = $this->getSubfield($field, 'z');
                }
                $link = [
                    'url' => $this->getSubfield($field, 'u'),
                    'text' => $linkText,
                    'source' => $this->source
                ];
                $data['online_urls_str_mv'][] = json_encode($link);
            }
        }

        // Holdings
        $data['holdings_txtP_mv']
            = $this->getFieldsSubfields(
                [
                    [MarcRecord::GET_NORMAL, '852', [
                        'a' => 1, 'b' => 1, 'h' => 1, 'z' => 1
                    ]]
                ]
            );
        if (!empty($data['holdings_txtP_mv'])) {
            $updateFunc = function(&$val, $k, $source) {
                $val .= " $source";
            };
            array_walk($data['holdings_txtP_mv'], $updateFunc, $this->source);
        }

        // Access restrictions
        if ($restrictions = $this->getAccessRestrictions()) {
            $data['restricted_str'] = $restrictions;
        }

        // ISMN
        foreach ($this->getFields('024') as $field024) {
            if ($this->getIndicator($field024, 1) == '2') {
                $ismn = $this->getSubfield($field024, 'a');
                $ismn = str_replace('-', '', $ismn);
                if (!preg_match('{([0-9]{13})}', $ismn, $matches)) {
                    continue;
                };
                $data['ismn_isn_mv'] = $matches[1];
            }
        }

        // Project ID in 960 (Fennica)
        if ($this->getDriverParam('projectIdIn960', false)) {
            $data['project_id_str_mv'] = $this->getFieldsSubfields(
                [
                    [MarcRecord::GET_NORMAL, '960', ['a' => 1]]
                ]
            );
        }

        // Hierarchical Categories (MetaLib)
        foreach ($this->getFields('976') as $field976) {
            $category = $this->getSubfield($field976, 'a');
            $category = trim(
                str_replace(['/', '\\'], '', $category), " -\t\n\r\0\x0B"
            );
            if (!$category) {
                continue;
            }
            $sub = $this->getSubfield($field976, 'b');
            $sub = trim(str_replace(['/', '\\'], '', $sub), " -\t\n\r\0\x0B");
            if ($sub) {
                $category .= "/$sub";
            }
            $data['category_str_mv'][] = $category;
        };

        // Hierarchical categories (e.g. SFX)
        if ($this->getDriverParam('categoriesIn650', false)) {
            foreach ($this->getFields('650') as $field650) {
                $category = $this->getSubfield($field650, 'a');
                $category = trim(str_replace(['/', '\\'], '', $category));
                if (!$category) {
                    continue;
                }
                $sub = $this->getSubfield($field650, 'x');
                $sub = trim(str_replace(['/', '\\'], '', $sub));
                if ($sub) {
                    $category .= "/$sub";
                }
                $data['category_str_mv'][] = $category;
            }
        }

        return $data;
    }

    /**
     * Merge component parts to this record
     *
     * @param MongoCollection $componentParts Component parts to be merged
     *
     * @return int Count of records merged
     */
    public function mergeComponentParts($componentParts)
    {
        $count = 0;
        $parts = [];
        foreach ($componentParts as $componentPart) {
            $data = MetadataUtils::getRecordData($componentPart, true);
            $marc = new MARCRecord($data, '', $this->source, $this->idPrefix);
            $title = $marc->getFieldSubfields(
                '245', ['a' => 1, 'b' => 1, 'n' => 1, 'p' => 1]
            );
            $uniTitle
                = $marc->getFieldSubfields('240', ['a' => 1, 'n' => 1, 'p' => 1]);
            if (!$uniTitle) {
                $uniTitle = $marc->getFieldSubfields(
                    '130', ['a' => 1, 'n' => 1, 'p' => 1]
                );
            }
            $additionalTitles = $marc->getFieldsSubfields(
                [
                    [MarcRecord::GET_NORMAL, '740', ['a' => 1]]
                ]
            );
            $authors = $marc->getFieldsSubfields(
                [
                    [MarcRecord::GET_NORMAL, '100', ['a' => 1, 'e' => 1]],
                    [MarcRecord::GET_NORMAL, '110', ['a' => 1, 'e' => 1]]
                ]
            );
            $additionalAuthors = $marc->getFieldsSubfields(
                [
                    [MarcRecord::GET_NORMAL, '700', ['a' => 1, 'e' => 1]],
                    [MarcRecord::GET_NORMAL, '710', ['a' => 1, 'e' => 1]]
                ]
            );
            $duration = $marc->getFieldsSubfields(
                [
                    [MarcRecord::GET_NORMAL, '306', ['a' => 1]]
                ]
            );
            $languages = [substr($marc->getField('008'), 35, 3)];
            $languages = array_unique(
                array_merge(
                    $languages, $marc->getFieldsSubfields(
                        [
                            [MarcRecord::GET_NORMAL, '041', ['a' => 1]],
                            [MarcRecord::GET_NORMAL, '041', ['d' => 1]]
                        ],
                        false, true, true
                    )
                )
            );
            $originalLanguages = $marc->getFieldsSubfields(
                [
                    [MarcRecord::GET_NORMAL, '041', ['h' => 1]]
                ],
                false, true, true
            );
            $subtitleLanguages = $marc->getFieldsSubfields(
                [
                    [MarcRecord::GET_NORMAL, '041', ['j' => 1]]
                ],
                false, true, true
            );
            $id = $componentPart['_id'];

            $newField = [
                'i1' => ' ',
                'i2' => ' ',
                's' => [
                    ['a' => $id]
                 ]
            ];
            if ($title) {
                $newField['s'][] = ['b' => $title];
            }
            if ($authors) {
                $newField['s'][] = ['c' => array_shift($authors)];
                foreach ($authors as $author) {
                    $newField['s'][] = ['d' => $author];
                }
            }
            foreach ($additionalAuthors as $addAuthor) {
                $newField['s'][] = ['d' => $addAuthor];
            }
            if ($uniTitle) {
                $newField['s'][] = ['e' => $uniTitle];
            }
            if ($duration) {
                $newField['s'][] = ['f' => reset($duration)];
            }
            foreach ($additionalTitles as $addTitle) {
                $newField['s'][] = ['g' => $addTitle];
            }
            foreach ($languages as $language) {
                if (preg_match('/^\w{3}$/', $language) && $language != 'zxx'
                    && $language != 'und'
                ) {
                    $newField['s'][] = ['h' => $language];
                }
            }
            foreach ($originalLanguages as $language) {
                if (preg_match('/^\w{3}$/', $language) && $language != 'zxx'
                    && $language != 'und'
                ) {
                    $newField['s'][] = ['i' => $language];
                }
            }
            foreach ($subtitleLanguages as $language) {
                if (preg_match('/^\w{3}$/', $language) && $language != 'zxx'
                    && $language != 'und'
                ) {
                    $newField['s'][] = ['j' => $language];
                }
            }

            $key = MetadataUtils::createIdSortKey($id);
            $parts["$key $count"] = $newField;
            ++$count;
        }
        ksort($parts);
        $this->fields['979'] = array_values($parts);
        return $count;
    }

    /**
     * Dedup: Return format from predefined values
     *
     * @return string
     */
    public function getFormat()
    {
        // Custom predefined type in 977a
        $field977a = $this->getFieldSubfields('977', ['a' => 1]);
        if ($field977a) {
            return $field977a;
        }

        // Dissertations and Thesis
        if (isset($this->fields['502'])) {
            return 'Dissertation';
        }
        if (isset($this->fields['509'])) {
            $field509a = MetadataUtils::stripTrailingPunctuation(
                $this->getFieldSubfields('509', ['a' => 1])
            );
            switch (strtolower($field509a)) {
            case 'kandidaatintyö':
            case 'kandidatarbete':
                return 'BachelorsThesis';
            case 'pro gradu -työ':
            case 'pro gradu':
                return 'ProGradu';
            case 'laudaturtyö':
            case 'laudaturavh':
                return 'LaudaturThesis';
            case 'lisensiaatintyö':
            case 'lic.avh.':
                return 'LicentiateThesis';
            case 'diplomityö':
            case 'diplomarbete':
                return 'MastersThesis';
            case 'erikoistyö':
            case 'vicenot.ex.':
                return 'Thesis';
            case 'lopputyö':
            case 'rättsnot.ex.':
                return 'Thesis';
            case 'amk-opinnäytetyö':
            case 'yh-examensarbete':
                return 'BachelorsThesisPolytechnic';
            case 'ylempi amk-opinnäytetyö':
            case 'högre yh-examensarbete':
                return 'MastersThesisPolytechnic';
            }
            return 'Thesis';
        }
        return parent::getFormat();
    }

    /**
     * Check if record has access restrictions.
     *
     * @return string 'restricted' or more specific licence id if restricted,
     * empty string otherwise
     */
    public function getAccessRestrictions()
    {
        // Access restrictions based on location
        $restricted = $this->getDriverParam('restrictedLocations', '');
        if ($restricted) {
            $restricted = array_flip(
                array_map(
                    'trim',
                    explode(',', $restricted)
                )
            );
        }
        if ($restricted) {
            foreach ($this->getFields('852') as $field852) {
                $locationCode = trim($this->getSubfield($field852, 'b'));
                if (isset($restricted[$locationCode])) {
                    return 'restricted';
                }
            }
        }
        foreach ($this->getFields('540') as $field) {
            if (strcasecmp($this->getSubfield($field, '3'), 'metadata') == 0
                && strcasecmp($this->getSubfield($field, 'a'), 'ei poimintaa') == 0
            ) {
                return 'restricted';
            }
        }
        return '';
    }

    /**
     * Return publication year/date range
     *
     * @return array Date range
     */
    protected function getPublicationDateRange()
    {
        $field008 = $this->getField('008');
        if ($field008) {
            switch (substr($field008, 6, 1)) {
            case 'c':
                $year = substr($field008, 7, 4);
                $startDate = "$year-01-01T00:00:00Z";
                $endDate = '9999-12-31T23:59:59Z';
                break;
            case 'd':
            case 'i':
            case 'k':
            case 'm':
            case 'q':
                $year1 = substr($field008, 7, 4);
                $year2 = substr($field008, 11, 4);
                $startDate = "$year1-01-01T00:00:00Z";
                $endDate = "$year2-12-31T23:59:59Z";
                break;
            case 'e':
                $year = substr($field008, 7, 4);
                $mon = substr($field008, 11, 2);
                $day = substr($field008, 13, 2);
                $startDate = "$year-$mon-{$day}T00:00:00Z";
                $endDate = "$year-$mon-{$day}T23:59:59Z";
                break;
            case 's':
            case 't':
            case 'u':
                $year = substr($field008, 7, 4);
                $startDate = "$year-01-01T00:00:00Z";
                $endDate = "$year-12-31T23:59:59Z";
                break;
            }
        }

        if (!isset($startDate) || !isset($endDate)
            || !MetadataUtils::validateISO8601Date($startDate)
            || !MetadataUtils::validateISO8601Date($endDate)
        ) {
            $field = $this->getField('260');
            if ($field) {
                $year = $this->getSubfield($field, 'c');
                $matches = [];
                if ($year && preg_match('/(\d{4})/', $year, $matches)) {
                    $startDate = "{$matches[1]}-01-01T00:00:00Z";
                    $endDate = "{$matches[1]}-12-31T23:59:59Z";
                }
            }
        }

        if (!isset($startDate) || !isset($endDate)
            || !MetadataUtils::validateISO8601Date($startDate)
            || !MetadataUtils::validateISO8601Date($endDate)
        ) {
            $fields = $this->getFields('264');
            foreach ($fields as $field) {
                if ($this->getIndicator($field, 2) == '1') {
                    $year = $this->getSubfield($field, 'c');
                    $matches = [];
                    if ($year && preg_match('/(\d{4})/', $year, $matches)) {
                        $startDate = "{$matches[1]}-01-01T00:00:00Z";
                        $endDate = "{$matches[1]}-12-31T23:59:59Z";
                        break;
                    }
                }
            }
        }
        if (isset($startDate) && isset($endDate)
            && MetadataUtils::validateISO8601Date($startDate)
            && MetadataUtils::validateISO8601Date($endDate)
        ) {
            if ($endDate < $startDate) {
                global $logger;
                $logger->log(
                    'NdlMarcRecord',
                    "Invalid date range {$startDate} - {$endDate}, record "
                    . "{$this->source}." . $this->getID(),
                    Logger::WARNING
                );
                $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
            }
            return [$startDate, $endDate];
        }

        return '';
    }

    /**
     * Get an array of all fields relevant to allfields search
     *
     * @return string[]
     */
    protected function getAllFields()
    {
        $subfieldFilter = [
            '650' => ['0' => 1, '2' => 1, '6' => 1, '8' => 1],
            '773' => ['0' => 1, '6' => 1, '7' => 1, '8' => 1, 'w' => 1],
            '856' => ['0' => 1, '6' => 1, '8' => 1, 'q' => 1],
            '979' => ['0' => 1, 'a' => 1, 'f' => 1]
        ];
        $allFields = [];
        // Include ISBNs, also normalized if possible
        foreach ($this->getFields('020') as $field) {
            $isbns = $this->getSubfieldsArray($field, ['a' => 1, 'z' => 1]);
            foreach ($isbns as $isbn) {
                $allFields[] = $isbn;
                $isbn = MetadataUtils::normalizeISBN($isbn);
                if ($isbn) {
                    $allFields[] = $isbn;
                }
            }

        }
        foreach ($this->fields as $tag => $fields) {
            if (($tag >= 100 && $tag < 841) || $tag == 856 || $tag == 880
                || $tag == 979
            ) {
                foreach ($fields as $field) {
                    $subfields = $this->getAllSubfields(
                        $field,
                        isset($subfieldFilter[$tag])
                        ? $subfieldFilter[$tag] : ['0' => 1, '6' => 1, '8' => 1]
                    );
                    if ($subfields) {
                        $allFields = array_merge($allFields, $subfields);
                    }
                }
            }
        }
        $allFields = array_map(
            function($str) {
                return MetadataUtils::stripLeadingPunctuation(
                    MetadataUtils::stripTrailingPunctuation($str)
                );
            },
            $allFields
        );
        return array_values(array_unique($allFields));
    }

    /**
     * Get topic facet fields
     *
     * @return string[] Topics
     */
    protected function getTopicFacets()
    {
        return $this->getFieldsSubfields(
            [
                [MarcRecord::GET_NORMAL, '600', ['a' => 1, 'x' => 1]],
                [MarcRecord::GET_NORMAL, '610', ['a' => 1, 'x' => 1]],
                [MarcRecord::GET_NORMAL, '611', ['a' => 1, 'x' => 1]],
                [MarcRecord::GET_NORMAL, '630', ['a' => 1, 'x' => 1]],
                [MarcRecord::GET_NORMAL, '648', ['x' => 1]],
                [MarcRecord::GET_NORMAL, '650', ['a' => 1, 'x' => 1]],
                [MarcRecord::GET_NORMAL, '651', ['x' => 1]],
                [MarcRecord::GET_NORMAL, '655', ['x' => 1]]
            ],
            false, true, true
        );
    }

    /**
     * Get all language codes
     *
     * @return string[] Language codes
     */
    protected function getLanguages()
    {
        $languages = [substr($this->getField('008'), 35, 3)];
        $languages2 = $this->getFieldsSubfields(
            [
                [MarcRecord::GET_NORMAL, '041', ['a' => 1]],
                [MarcRecord::GET_NORMAL, '041', ['d' => 1]],
                // 979h = component part language
                [MarcRecord::GET_NORMAL, '979', ['h' => 1]]
            ],
            false, true, true
        );
        $results = [];
        foreach (array_merge($languages, $languages2) as $language) {
            if (preg_match('/^\w{3}$/', $language)
                && $language != 'zxx'
                && $language != 'und'
            ) {
                $results[] = $language;
            }
        }
        return $results;
    }
}
