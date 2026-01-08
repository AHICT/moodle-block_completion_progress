// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Overview table filter.
 *
 * @module  block_completion_progress/overview_filter
 * @copyright 2021 Tomo Tsuyuki <tomotsuyuki@catalyst-au.net>
 * @copyright 2025 Jonathon Fowler <fowlerj@usq.edu.au>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DataFilter from 'core/datafilter';
import DataFilterSelectors from 'core/datafilter/selectors';
import * as DynamicTable from 'core_table/dynamic';
import Notification from 'core/notification';
import BlockInstanceFilter from 'block_completion_progress/datafilter/filtertypes/blockinstanceid';

/**
 * Initialise the overview table filter.
 * @param {String} filterRegionId The id of the filter element.
 * @param {Integer} blockInstanceId The block instance id.
 */
export const init = async (filterRegionId, blockInstanceId) => {
    const filterSet = document.querySelector(`#${filterRegionId}`);

    const applyFilters = (filters, pendingPromise) => {
        DynamicTable
            .setFilters(
                DynamicTable.getTableFromId(filterSet.dataset.tableRegion),
                {
                    jointype: parseInt(filterSet.querySelector(DataFilterSelectors.filterset.fields.join).value, 10),
                    filters,
                }
            )
            .then(result => {
                pendingPromise.resolve();
                return result;
            })
            .catch(Notification.exception);
    };

    const dataFilter = new DataFilter(filterSet, applyFilters);
    dataFilter.activeFilters.blockinstanceid = new BlockInstanceFilter('blockinstanceid', filterSet, blockInstanceId);
    dataFilter.init();
};
