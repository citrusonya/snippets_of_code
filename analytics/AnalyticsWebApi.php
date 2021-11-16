<?php

class analyticsWebApi
{
    /**
     * Запрос для построения листа Excel
     *
     * @param array $request
     * @return Query
     * @throws Exception
     */
    public static function productsMovementReportQuery(array $request): Query
    {
        ArrayHelper::validateRequest($request, ['user_id', 'business_id']);

        $userId = $request['user_id'];
        $businessId = $request['business_id'];

        # Фильтр по дате от
        if (empty($request['search']['date']['from'])) {
            $searchDateFrom = "(now() - interval '14 days')";
        } else {
            $searchDateFrom = "'" . (new DateTime($request['search']['date']['from']))->format('Y-m-d') . "'";
        }

        # Фильтр по дате до
        if (empty($request['search']['date']['to'])) {
            $searchDateTo = 'now()';
        } else {
            $searchDateTo = "'" . (new DateTime($request['search']['date']['to']))->format('Y-m-d') . "'";
        }

        # Фильтр по валюте
        $searchCurrencyId = new Expression($request['search']['currency'] ?? SomeClass::DEFAULT_CURRENCY_ID);

        # Фильтр по организациям
        $searchOrganizationId = $request['search']['orgs'] ?? null;

        $subQueryGetOrgIds = (new Query())
            ->select(new Expression('array_agg(id)'))
            ->from(['o' => SomeClass::tableNameWithSchema()])
            ->where(
                [
                    'and',
                    ['o.business_id' => $businessId],
                    [
                        'o.type_id' => [
                            SomeClass::TYPE_1,
                            SomeClass::TYPE_2,
                            SomeClass::TYPE_3,
                            SomeClass::TYPE_4,
                        ]
                    ],
                ]
            )
            ->andFilterWhere(['o.id' => $searchOrganizationId]);

        $excludedServiceIDs = [SomeClass::SERVICE_ID];

        # Фильтр по поставщикам
        if (!empty($request['search']['vendor_id'])) {
            $searchVendorId = implode(', ', $request['search']['vendor_id']);
        } else {
            $searchVendorId = '';
        }

        # Фильтр по номенклатуре
        if (!empty($request['search']['outer_product_id'])) {
            $searchOuterProductId = implode(', ', $request['search']['outer_product_id']);
        } else {
            $searchOuterProductId = '';
        }

        # Фильтр по сотруднику
        $employeeId = $request['search']['employee_id'] ?? '';

        $paramsQuery = (new Query())
            ->select(
                [
                    'user_id'              => new Expression($userId),
                    'business_id'          => new Expression($businessId),
                    'org_ids'              => $subQueryGetOrgIds,
                    'currency_id'          => $searchCurrencyId,
                    'date_from'            => new Expression($searchDateFrom . "::date"),
                    'date_to'              => new Expression($searchDateTo . "::date"),
                    'vendor_id'            => new Expression('array[' . $searchVendorId . ']::int[]'),
                    'outer_product_id'     => new Expression('array[' . $searchOuterProductId . ']::int[]'),
                    'employee_id'          => new Expression('array[' . $employeeId . ']::int[]'),
                    'excluded_service_ids' => new Expression('array[' . implode(', ', $excludedServiceIDs) . ']')
                ]
            );

        $orgInfoQuery = (new Query())
            ->select(
                [
                    'o.id',
                    'o.type_id',
                    'o.business_id',
                ]
            )
            ->from(['o' => SomeClass::tableNameWithSchema()])
            ->innerJoin(['p' => 'params'], 'o.id = any(p.org_ids)');

        $dealersQuery = (new Query())
            ->select(
                [
                    'o.id',
                    'o.business_id',
                ]
            )
            ->from(['o' => SomeClass::tableNameWithSchema()])
            ->innerJoin(['p' => 'params'], 'p.business_id = o.business_id')
            ->where(
                [
                    'o.type_id' => [
                        SomeClass::TYPE_3,
                        SomeClass::TYPE_4,
                    ],
                ]
            );

        $delegateQuery = (new Query())
            ->select(
                [
                    'dl.id',
                    'osv.organization_id',
                    'osv.value',
                ]
            )
            ->from(['dl' => 'dealers'])
            ->innerJoin(['osv' => SomeClass::tableNameWithSchema()], 'osv.organization_id = dl.id')
            ->innerJoin(['os' => SomeClass::tableNameWithSchema()], 'os.id = osv.setting_id')
            ->where(
                [
                    'osv.value' => 'off',
                    'os.name'   => SomeClass::SETTING,
                ]
            );

        $presetsQuery = (new Query())
            ->select(
                [
                    'org_id'               => 'oi.id',
                    'type_id'              => 'oi.type_id',
                    'services'             => new Expression('array_agg(distinct l.service_id)'),
                    'potential_service_id' => new Expression(
                        'coalesce(uas.service_id, (
              case when array_length(array_agg(distinct l.service_id), 1) > 1 then null 
              else (array_agg(distinct l.service_id))[1] end))'
                    ),
                ]
            )
            ->from(['oi' => 'org_info'])
            ->innerJoin(['p' => 'params'], 'true')
            ->innerJoin(
                ['lo' => SomeClass::tableNameWithSchema()],
                'lo.org_id = oi.id and lo.status_id = 1 and coalesce(lo.is_deleted, 0) = 0 and (now() between lo.fd and lo.td)'
            )
            ->innerJoin(['l' => SomeClass::tableNameWithSchema()], 'l.id = lo.license_id')
            ->innerJoin(['als' => SomeClass::tableNameWithSchema()], 'als.id = l.service_id and als.type_id = 1')
            ->leftJoin(
                ['uas' => SomeClass::tableNameWithSchema()],
                'uas.organization_id = oi.id and uas.user_id = p.user_id'
            )
            ->groupBy(['oi.id', 'uas.service_id', 'oi.type_id']);

        $intSettingsValueQuery = (new Query())
            ->select(
                [
                    'isv.org_id',
                    'isv.setting_id',
                    'setting_value' => new Expression("nullif(isv.value, '')::int"),
                ]
            )
            ->from(['pr' => 'presets'])
            ->innerJoin(['isv' => SomeClass::tableNameWithSchema()], 'isv.org_id = pr.org_id')
            ->innerJoin(
                ['iss' => SomeClass::tableNameWithSchema()],
                'iss.id = isv.setting_id and iss.service_id = pr.potential_service_id and iss.is_active = 1'
            )
            ->where(['iss.name' => 'main_org']);

        $settingsQuery = (new Query())
            ->select(
                [
                    'pr.org_id',
                    'pr.potential_service_id',
                    'is_in_service'       => new Expression('coalesce(pr.potential_service_id, 0) = any (pr.services)'),
                    'org_id_for_analogs'  => new Expression(
                        "case when coalesce(dl.value, 'on') = 'off' then d.id else pr.org_id end"
                    ),
                    'org_id_for_rsr'      => new Expression(
                        "case when coalesce(dl.value, 'on') = 'off' and pr.type_id = 1 then pr.org_id when coalesce(dl.value, 'on') = 'off' and pr.type_id != 1 then null else pr.org_id end"
                    ),
                    'org_id_for_matching' => new Expression('coalesce(isv.setting_value, isv.org_id)'),
                ]
            )
            ->from(['pr' => 'presets'])
            ->innerJoin(['p' => 'params'], 'true')
            ->innerJoin(['isv' => 'int_settings_value'], 'isv.org_id = pr.org_id')
            ->leftJoin(['d' => 'dealers'], 'd.business_id = p.business_id')
            ->leftJoin(['dl' => 'delegate'], 'dl.organization_id = d.id');

        $catalogsQuery = (new Query())
            ->select(
                [
                    'rsr.rest_org_id',
                    'rsr.supp_org_id',
                    'cat_id' => new Expression('array_agg(rsr.cat_id)'),
                ]
            )
            ->from(['s' => 'settings'])
            ->join('CROSS JOIN', ['p' => 'params'])
            ->innerJoin(
                ['rsr' => SomeClass::tableNameWithSchema()],
                'rsr.rest_org_id = s.org_id_for_rsr and rsr.invite = 1 and rsr.status = 1 and coalesce(rsr.cat_id, 0) != 0'
            )
            ->innerJoin(['c' => SomeClass::tableNameWithSchema()], 'c.id = rsr.cat_id and c.currency_id = p.currency_id')
            ->groupBy(['rsr.rest_org_id', 'rsr.supp_org_id']);

        $catalogsGoodsQuery = (new Query())
            ->select(
                [
                    's.org_id',
                    'cg.cat_id',
                    'cg.base_goods_id',
                    'c.supp_org_id',
                    'cbg.product',
                    'cg.price',
                    'map_coefficient' => 'opm.coefficient',
                    'product_uid'     => new Expression('op.outer_uid'),
                    'store_uid'       => new Expression('os.outer_uid'),
                ]
            )
            ->from(['s' => 'settings'])
            ->innerJoin(['p' => 'params'], 'true')
            ->innerJoin(['c' => 'catalogs'], 'c.rest_org_id = s.org_id')
            ->innerJoin(['cg' => SomeClass::tableNameWithSchema()], 'cg.cat_id = any(c.cat_id)')
            ->innerJoin(['cbg' => SomeClass::tableNameWithSchema()], 'cbg.id = cg.base_goods_id')
            ->leftJoin(
                ['opm' => SomeClass::tableNameWithSchema()],
                'opm.organization_id = s.org_id_for_matching and opm.product_id = cg.base_goods_id and opm.service_id = s.potential_service_id'
            )
            ->leftJoin(['op' => SomeClass::tableNameWithSchema()], 'op.id = opm.outer_product_id')
            ->leftJoin(['os' => SomeClass::tableNameWithSchema()], 'os.id = opm.outer_store_id');

        $mainProductAnalogQuery = (new Query())
            ->select(
                [
                    'pa.client_id',
                    'pa.product_id',
                    'parent_id'      => new Expression('coalesce(pa.parent_id, pa.product_id)'),
                    'pa_coefficient' => 'pa.coefficient',
                ]
            )
            ->distinct()
            ->from(['s' => 'settings'])
            ->innerJoin(['pa' => SomeClass::tableNameWithSchema()], 'pa.client_id = s.org_id_for_analogs');

        $intOuterProductQuery = (new Query())
            ->select(
                [
                    'op.outer_uid',
                    'op.name',
                    'op.service_id',
                    'unit_name'         => 'ou.name',
                    'org_ids'           => new Expression('array_agg(op.org_id)'),
                    'outer_product_ids' => new Expression('array_agg(op.id)'),
                ]
            )
            ->from(['s' => 'settings'])
            ->innerJoin(
                ['op' => SomeClass::tableNameWithSchema()],
                [
                    'op.org_id'     => new Expression('s.org_id_for_matching'),
                    'op.service_id' => new Expression('s.potential_service_id'),
                    'op.is_deleted' => SomeClass::STATUS,
                ]
            )
            ->innerJoin(['ou' => SomeClass::tableNameWithSchema()], 'ou.id = op.outer_unit_id')
            ->groupBy(['op.outer_uid', 'op.name', 'op.service_id', 'ou.name']);

        $matchingPrevQuery = (new Query())
            ->select(
                [
                    'cg.org_id',
                    'op.outer_uid',
                    'op.name',
                    'op.unit_name',
                    'cg.base_goods_id',
                    'cg.price',
                    'pa.parent_id',
                    'analogs_group_name' => new Expression(
                        'case when pa.parent_id is not null then coalesce(pp.product, cbg.product) else null end'
                    ),
                    'id_for_grouping'    => new Expression('coalesce(pa.parent_id, cg.base_goods_id)'),
                    'pa_coefficient'     => 'pa.pa_coefficient',
                    'map_coefficient'    => 'cg.map_coefficient',
                    'cg.store_uid',
                ]
            )
            ->from(['cg' => 'catalogs_goods'])
            ->leftJoin(['op' => 'int_outer_product'], 'cg.org_id = any(op.org_ids) and cg.product_uid = op.outer_uid')
            ->leftJoin(['pa' => 'main_product_analog'], 'pa.product_id = cg.base_goods_id')
            ->leftJoin(['pp' => 'catalogs_goods'], 'pp.org_id = any(op.org_ids) and pp.base_goods_id = pa.parent_id')
            ->leftJoin(['cbg' => SomeClass::tableNameWithSchema()], 'cbg.id = pa.parent_id');

        $coefficientConvertedQuery = (new Query())
            ->select(['vl' => new Expression('max(sq.sqa)')])
            ->from(['sq(sqa)' => new Expression('unnest(array_agg(c.k))')])
            ->groupBy([new Expression('round(sq.sqa, 3)')])
            ->orderBy(new Expression('count(round(sq.sqa, 2)) DESC'))
            ->limit(1);

        $coefficientSubQuery = (new Query())
            ->select(['k' => new Expression('mp.pa_coefficient / nullif(b.pa_coefficient * b.map_coefficient, 0)')]);

        $matchingQuery = (new Query())
            ->select(
                [
                    'mp.org_id',
                    'mp.outer_uid',
                    'mp.name',
                    'mp.unit_name',
                    'mp.base_goods_id',
                    'mp.parent_id',
                    'mp.id_for_grouping',
                    'mp.store_uid',
                    'mp.analogs_group_name',
                    'coefficient_origin'    => new Expression('1 / nullif(max(mp.map_coefficient), 0)'),
                    'coefficient_converted' => $coefficientConvertedQuery,
                    'coefficient_variants'  => new Expression('array_agg(round(c.k, 5))')
                ]
            )
            ->from(['mp' => 'matching_prev'])
            ->join(
                'FULL JOIN',
                ['b' => 'matching_prev'],
                'b.id_for_grouping = mp.id_for_grouping and b.map_coefficient is not null'
            )
            # Можно будет вернуться к этому месту для оптимизации full join через lateral
            ->join('CROSS JOIN LATERAL', ['c' => $coefficientSubQuery])
            ->groupBy(
                [
                    'mp.org_id',
                    'mp.outer_uid',
                    'mp.name',
                    'mp.unit_name',
                    'mp.base_goods_id',
                    'mp.parent_id',
                    'mp.id_for_grouping',
                    'mp.store_uid',
                    'mp.analogs_group_name'
                ]
            );

        $storeNameQuery = (new Query())
            ->select(
                [
                    'os.service_id',
                    'os.name',
                    'os.outer_uid',
                    'os.org_id',
                    'os_ids' => new Expression('array_agg(distinct os.id)'),
                ]
            )
            ->from(['s' => 'settings'])
            ->innerJoin(
                ['os' => SomeClass::tableNameWithSchema()],
                [
                    'os.org_id'     => new Expression('s.org_id'),
                    'os.service_id' => new Expression('s.potential_service_id'),
                    'os.is_active'  => SomeClass::ACTIVE,
                    'os.is_deleted' => SomeClass::DELETED,
                ]
            )
            ->groupBy(['os.service_id', 'os.name', 'os.outer_uid', 'os.org_id']);

        $restsQuery = (new Query())
            ->select(
                [
                    'bs.org_id',
                    'op.outer_uid',
                    'store_guid' => 'os.outer_uid',
                    'bs.amount',
                ]
            )
            ->from(['s' => 'settings'])
            ->innerJoin(['bs' => SomeClass::tableNameWithSchema()], 'bs.org_id = s.org_id')
            ->innerJoin(
                ['op' => 'int_outer_product'],
                'bs.outer_product_id = any(op.outer_product_ids) and op.service_id = s.potential_service_id'
            )
            ->innerJoin(['os' => SomeClass::tableNameWithSchema()], 'os.id = bs.outer_store_id');

        $priceAnsSuppQuery = (new Query())
            ->select(
                [
                    'cg.base_goods_id',
                    'cg.supp_org_id',
                    'min_price' => new Expression('min(cg.price)'),
                    'org_ids'   => new Expression('array_agg(distinct cg.org_id)'),
                ]
            )
            ->from(['cg' => 'catalogs_goods'])
            ->groupBy(['cg.base_goods_id', 'cg.supp_org_id']);

        $outerOrdersQuery = (new Query())
            ->select(
                [
                    'op.outer_uid',
                    'oo.outer_id',
                    'oop.document_guid',
                    'op.name outer_name',
                    'quantity'      => new Expression('sum(abs(oop.quantity))'),
                    'price'         => new Expression('sum(abs(oop.price))'),
                    'document_type' => new Expression(
                        "case when oo.document_type = 'INCOMING_INVOICE' then 'incom' else 'sale' end"
                    ),
                    'oo.store_guid',
                    'op.unit_name'
                ]
            )
            ->from(['p' => 'params'])
            ->innerJoin(['oo' => SomeClass::tableNameWithSchema()], 'oo.business_id = p.business_id')
            ->innerJoin(['oop' => SomeClass::tableNameWithSchema()], 'oop.document_guid = oo.outer_id')
            ->innerJoin(['op' => 'int_outer_product'], 'op.outer_uid = oop.product_guid')
            ->where(
                [
                    'and',
                    [
                        'oo.document_type' => [
                            'SALES_DOCUMENT',
                            'WRITEOFF_DOCUMENT',
                            'OUTGOING_INVOICE',
                            'INTERNAL_TRANSFER',
                            'INCOMING_INVOICE'
                        ]
                    ],
                    [
                        'between',
                        new Expression('(oo.ordered_at)::date'),
                        new Expression('p.date_from'),
                        new Expression('p.date_to')
                    ],
                ]
            )
            ->groupBy(
                [
                    'op.outer_uid',
                    'oo.outer_id',
                    'oop.document_guid',
                    'op.name',
                    'op.service_id',
                    'oo.store_guid',
                    'document_type',
                    'op.unit_name'
                ]
            );

        $salesIncomeQuery = (new Query())
            ->select(
                [
                    'oo.outer_uid',
                    'oo.store_guid',
                    'oo.unit_name',
                    'outer_name',
                    'inc_quantity'  => new Expression(
                        "sum(case when oo.document_type = 'incom' then oo.quantity else 0 end)"
                    ),
                    'inc_price'     => new Expression(
                        "sum(case when oo.document_type = 'incom' then oo.price else 0 end)"
                    ),
                    'sale_quantity' => new Expression(
                        "sum(case when oo.document_type = 'sale' then oo.quantity else 0 end)"
                    ),
                    'sale_price'    => new Expression(
                        "sum(case when oo.document_type = 'sale' then oo.price else 0 end)"
                    ),
                ]
            )
            ->from(['oo' => 'outer_orders'])
            ->groupBy(['oo.outer_uid', 'oo.store_guid', 'outer_name', 'oo.unit_name']);

        $waybillsContentQuery = (new Query())
            ->select(
                [
                    'wc.id waybill_content_id',
                    'w.service_id',
                    'price'             => 'wc.price_with_vat',
                    'quantity'          => 'wc.quantity_waybill',
                    'order_content_ids' => 'wc.order_content_id',
                    'store_uid'         => 'os.outer_uid',
                    'product_uid'       => 'op.outer_uid',
                    'org_id'            => 'w.acquirer_id',
                    'o.vendor_id',
                    'op.name'
                ]
            )
            ->from(['s' => 'settings'])
            ->join('CROSS JOIN', ['p' => 'params'])
            ->innerJoin(
                ['w' => SomeClass::tableNameWithSchema()],
                'w.acquirer_id = s.org_id and w.service_id = s.potential_service_id and w.service_id != any(p.excluded_service_ids) and w.doc_date between p.date_from and p.date_to'
            )
            ->innerJoin(['wc' => SomeClass::tableNameWithSchema()], 'wc.waybill_id = w.id')
            ->innerJoin(['op' => SomeClass::tableNameWithSchema()], 'op.id = wc.outer_product_id')
            ->innerJoin(['os' => SomeClass::tableNameWithSchema()], 'os.id = w.outer_store_id')
            ->innerJoin(['oc' => SomeClass::tableNameWithSchema()], 'oc.id = wc.order_content_id')
            ->innerJoin(['o' => SomeClass::tableNameWithSchema()], 'o.id = oc.order_id');

        $orderContentQuery = (new Query())
            ->select(
                [
                    's.org_id',
                    'oc.product_id',
                    'min_price'              => new Expression(
                        'min(coalesce(oc.inspection_price, oc.accepted_price, oc.plan_price))'
                    ),
                    'min_price_wb_priority'  => new Expression('min(wc.price)'),
                    'agg_order_content_id'   => new Expression('array_agg(oc.id)'),
                    'agg_waybill_content_id' => new Expression('array_agg(wc.waybill_content_id)')
                ]
            )
            ->from(['s' => 'settings'])
            ->innerJoin(['p' => 'params'], 'true')
            ->innerJoin(
                ['o' => SomeClass::tableNameWithSchema()],
                [
                    'and',
                    ['o.client_id' => new Expression('s.org_id')],
                    [
                        'o.status' => [
                            SomeClass::SOME_1,
                            SomeClass::SOME_2,
                            SomeClass::SOME_3,
                            SomeClass::SOME_4,
                            SomeClass::SOME_5,
                            SomeClass::SOME_5,
                            SomeClass::SOME_6,
                            SomeClass::SOME_7,
                        ]
                    ],
                    [
                        'o.order_type_id' => [
                            SomeClass::SOME_1,
                            SomeClass::SOME_2,
                            SomeClass::SOME_3,
                            SomeClass::SOME_4,
                            SomeClass::SOME_5,
                            SomeClass::SOME_6,
                        ]
                    ],
                    ['o.currency_id' => new Expression('p.currency_id')],
                    ['between', 'o.created_at', new Expression('p.date_from'), new Expression('p.date_to')],
                ]
            )
            ->innerJoin(
                ['oc' => SomeClass::tableNameWithSchema()],
                [
                    'and',
                    ['oc.order_id' => new Expression('o.id')],
                    [
                        '>',
                        new Expression('coalesce(oc.inspection_quantity, oc.accepted_quantity, oc.plan_quantity, 0)'),
                        0
                    ],
                ]
            )
            ->leftJoin(['wc' => 'waybills_content'], 'oc.id = wc.order_content_ids')
            ->groupBy(['s.org_id', 'oc.product_id']);

        $matchingProductsAndPriceQueryMinPriceQuery = new Expression(
            'coalesce(min_price_wb_priority, coalesce(oc.min_price , ps.min_price) * coalesce(coefficient_origin, coefficient_converted))::numeric(12, 2)'
        );

        $matchingProductsAndPriceQuery = (new Query())
            ->select(
                [
                    'mp.org_id',
                    'mp.outer_uid',
                    'mp.name',
                    'mp.base_goods_id',
                    'mp.analogs_group_name',
                    'mp.id_for_grouping',
                    'mp.unit_name',
                    'mp.store_uid',
                    'min_price'       => $matchingProductsAndPriceQueryMinPriceQuery,
                    'map_coefficient' => new Expression('coalesce(coefficient_origin, coefficient_converted)'),
                    'agg_order_content_id',
                    'agg_waybill_content_id',
                    'ps.supp_org_id',
                    'r.amount',
                ]
            )
            ->from(['mp' => 'matching'])
            ->leftJoin(
                ['ps' => 'price_and_supp'],
                'ps.base_goods_id = mp.base_goods_id and mp.org_id = any(ps.org_ids)'
            )
            ->leftJoin(
                ['oc' => 'order_content'],
                'oc.product_id = mp.base_goods_id and oc.org_id = mp.org_id'
            )
            ->leftJoin(
                ['r' => 'rests'],
                'r.org_id = mp.org_id and r.outer_uid = mp.outer_uid and r.store_guid = mp.store_uid',
            );

        $jsonSuppQuery = (new Query())
            ->select(
                [
                    'm.id_for_grouping',
                    'supp_id_and_price' => new Expression(
                        "jsonb_object_agg(coalesce (m.supp_org_id, '0'), m.min_price order by m.min_price desc)"
                    ),
                    'min_price'         => new Expression('min(m.min_price)')
                ]
            )
            ->from(['m' => 'matching_products_and_price'])
            ->groupBy(['id_for_grouping']);

        /**
         * Это решение потребует последующего пересмотра, оно нужно для --m.base_goods_id as "base_goods_ids"
         */
        $matchingOrdersQuery = (new Query())
            ->select(
                [
                    'waybill_content_id',
                    'agg_order_content_id',
                    'agg_waybill_content_id',
                    'm.outer_uid',
                    'm.name',
                    'unit_names'     => 'm.unit_name',
                    'm.analogs_group_name',
                    'm.store_uid',
                    'quantity_order' => 'wc.quantity',
                    'price_order'    => 'wc.price',
                    'jt.supp_id_and_price',
                    'jt.min_price',
                    'm.org_id',
                ]
            )
            ->distinct()
            ->from(['m' => 'matching_products_and_price'])
            ->leftJoin(
                ['wc' => 'waybills_content'],
                'wc.product_uid = m.outer_uid and wc.store_uid = m.store_uid and m.org_id = wc.org_id and wc.vendor_id = m.supp_org_id'
            )
            ->innerJoin(['jt' => 'json_supp'], 'jt.id_for_grouping = m.id_for_grouping')
            ->where(['is not', 'm.base_goods_id', null]);

        $incPriceQuery = new Expression(
            '(sum((coalesce(si.inc_price, mo.price_order)) * nullif(coalesce(mo.quantity_order, 1), 0)) / sum(nullif(coalesce(si.inc_quantity, mo.quantity_order), 0)) )::numeric(12, 2)'
        );

        $sumAllOrdersQuery = (new Query())
            ->select(
                [
                    'product_name'            => new Expression(
                        "string_agg(distinct coalesce(mo.name, si.outer_name), ', ')"
                    ),
                    'unit_names'              => new Expression(
                        "string_agg(distinct coalesce(mo.unit_names, si.unit_name), ', ')"
                    ),
                    'store_name'              => 'sn.name',
                    'name_analogs_group'      => 'mo.analogs_group_name',
                    'inc_avg_price_for_unit'  => $incPriceQuery,
                    'wc_id'                   => new Expression('array_agg(waybill_content_id)'),
                    'inc_quantity'            => new Expression(
                        'sum(nullif(coalesce(si.inc_quantity, mo.quantity_order), 0))'
                    ),
                    'inc_sum'                 => new Expression(
                        'sum(nullif(coalesce(si.inc_price, mo.price_order), 0))'
                    ),
                    'sale_avg_price_for_unit' => new Expression(
                        'sum(si.sale_price / nullif(si.sale_quantity, 0))::numeric(12, 2)'
                    ),
                    'sale_quantity'           => new Expression('sum(si.sale_quantity)'),
                    'sale_sum'                => new Expression('sum(si.sale_price)'),
                    'min_price'               => new Expression('min(mo.min_price)'),
                    'supp_id_and_price'       => 'mo.supp_id_and_price',
                    'rests_quantity'          => new Expression('sum(r.amount)'),
                    'org_ids'                 => new Expression('array_agg(distinct mo.org_id)'),
                ]
            )
            ->from(['mo' => 'matching_orders'])
            ->join(
                'FULL JOIN',
                ['si' => 'sales_incom_from_dictionaries'],
                'si.outer_uid = mo.outer_uid and si.store_guid = mo.store_uid'
            )
            ->leftJoin(
                ['r' => 'rests'],
                'r.outer_uid = mo.outer_uid and r.store_guid = mo.store_uid'
            )
            ->leftJoin(
                ['sn' => 'store_name'],
                'sn.outer_uid = coalesce(mo.store_uid, si.store_guid)'
            )
            ->groupBy(
                [
                    'coalesce(mo.name, si.outer_name)',
                    'coalesce(mo.unit_names, si.unit_name)',
                    'store_name',
                    'mo.analogs_group_name',
                    'mo.supp_id_and_price',
                ]
            );

        $pointIdsQuery = (new Query())
            ->select(new Expression('distinct mo.org_id'))
            ->from(['mo' => 'matching_orders']);

        $pointsCountQuery = (new Query())
            ->select(['point_count' => new Expression('count(*)')])
            ->from('point_ids');

        return (new Query())
            ->select(['sao.*', 'pc.point_count'])
            ->from(['sao' => 'sum_all_orders'])
            ->leftJoin(['pc' => 'points_count'], 'true')
            ->where(
                [
                    'OR',
                    ['is not', 'sao.inc_avg_price_for_unit', null],
                    ['is not', 'sao.inc_quantity', null],
                    ['is not', 'sao.inc_sum', null],
                    ['is not', 'sao.sale_avg_price_for_unit', null],
                    ['is not', 'sao.sale_quantity', null],
                    ['is not', 'sao.sale_sum', null],
                ]
            )
            ->orderBy(
                [
                    'sao.store_name'   => SORT_ASC,
                    'sao.product_name' => SORT_ASC,
                    new Expression('sao.name_analogs_group NULLS LAST')
                ]
            )
            ->withQuery($paramsQuery, 'params')
            ->withQuery($orgInfoQuery, 'org_info')
            ->withQuery($dealersQuery, 'dealers')
            ->withQuery($delegateQuery, 'delegate')
            ->withQuery($presetsQuery, 'presets')
            ->withQuery($intSettingsValueQuery, 'int_settings_value')
            ->withQuery($settingsQuery, 'settings')
            ->withQuery($catalogsQuery, 'catalogs')
            ->withQuery($catalogsGoodsQuery, 'catalogs_goods')
            ->withQuery($mainProductAnalogQuery, 'main_product_analog')
            ->withQuery($intOuterProductQuery, 'int_outer_product')
            ->withQuery($matchingPrevQuery, 'matching_prev')
            ->withQuery($matchingQuery, 'matching')
            ->withQuery($storeNameQuery, 'store_name')
            ->withQuery($restsQuery, 'rests')
            ->withQuery($priceAnsSuppQuery, 'price_and_supp')
            ->withQuery($outerOrdersQuery, 'outer_orders')
            ->withQuery($salesIncomeQuery, 'sales_incom_from_dictionaries')
            ->withQuery($waybillsContentQuery, 'waybills_content')
            ->withQuery($orderContentQuery, 'order_content')
            ->withQuery($matchingProductsAndPriceQuery, 'matching_products_and_price')
            ->withQuery($jsonSuppQuery, 'json_supp')
            ->withQuery($matchingOrdersQuery, 'matching_orders')
            ->withQuery($sumAllOrdersQuery, 'sum_all_orders')
            ->withQuery($pointIdsQuery, 'point_ids')
            ->withQuery($pointsCountQuery, 'points_count');
    }
}
