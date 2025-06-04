(function ($, Drupal,  once) {

  'use strict';

  Drupal.behaviors.strawberry_shortcake_activate = {
    attach: function (context, settings) {
      const visible_annotations = document.querySelectorAll('[data-sbf-annotations-flavorid]');
      console.log('Adding Clustering Checkboxes');

      const clusterMemberInfoUpdate = function (element) {
        const $endpoint_info = '/webannon/clustersbf/postgetinfo'
        const settings_info = {
          submit: {
            flavorid: element.dataset.sbfClustersFlavorid
          },
          base: false,
          url: '/do/' + element.dataset.sbfAnnotationsNodeuuid + $endpoint_info,
          element: element,
          type: 'POST',
          httpMethod: 'POST',
          progress: false,
        };
        const getClusterMemberInfoAjax = Drupal.ajax(settings_info);
        getClusterMemberInfoAjax.success = function (response, status) {
          return Promise.resolve(
            Drupal.Ajax.prototype.success.call(getClusterMemberInfoAjax, response, status),
          ).then(() => {
            if (status == "success" && response?.incurrentcluster !== undefined) {
              const $label = element.parentNode.querySelector('label');
              if (response.incurrentcluster == true) {
                element.checked = true;
                if ($label) {
                  $label.textContent = 'In Active Cluster';
                }
              }
              else {
                element.checked = false;
                if ($label) {
                  $label.textContent = 'Add to Active Cluster';
                }
              }
              if (Array.isArray(response?.otherclusters)) {
                if ($label && response.otherclusters.length) {
                  $label.textContent = $label.textContent + ' (also in:' + response.otherclusters.join(", ") + ')';
                }
              }
            }
          });
        };
        getClusterMemberInfoAjax.execute();
      }

      once('add-to-cluster',visible_annotations, context).forEach((element) => {
        if (element.dataset?.sbfAnnotationsFlavorid != undefined && element.dataset?.sbfAnnotationsNodeuuid != undefined) {
          const container = document.createElement('div')

          container.className = 'js-form-item js-form-type-checkbox checkbox form-check form-switch mb-3';
          const checkbox = document.createElement('input');
          checkbox.type = "checkbox";
          checkbox.name = "name";
          checkbox.value = "value";
          //Check if the value matches pattern?
          checkbox.dataset.sbfClustersFlavorid = element.dataset.sbfAnnotationsFlavorid;
          //Check if the value matches pattern?
          checkbox.dataset.sbfAnnotationsNodeuuid = element.dataset.sbfAnnotationsNodeuuid;
          checkbox.className = "form-checkbox form-check-input";
          checkbox.id = element.id + '-shortcake-check';
          const label = document.createElement('label')
          label.htmlFor = checkbox.id;
          label.className = "form-check-label";
          label.appendChild(document.createTextNode('Add to Active Cluster'));
          clusterMemberInfoUpdate(checkbox);
          /* const ckeditorAjaxDialog = Drupal.ajax({
          dialog: dialogSettings,
          dialogType: 'modal',
          selector: '.ckeditor-dialog-loading-link',
          url,
          progress: { type: 'throbber' },
          submit: {
            editor_object: existingValues,
           },
          });
          ckeditorAjaxDialog.execute();*/
          checkbox.addEventListener('change', function (event) {
            let $endpoint = '/webannon/clustersbf/postadd'
            if (event.target.checked == false) {
              $endpoint = '/webannon/clustersbf/postdelete'
            }
            const settings = {
              submit: {
                flavorid: event.target.dataset.sbfClustersFlavorid
              },
              base: false,
              url: '/do/' + event.target.dataset.sbfAnnotationsNodeuuid + $endpoint,
              element: event.target,
              type: 'POST',
              httpMethod: 'POST',
              progress: false
            };
            const addToClusterAjax = Drupal.ajax(settings);
            addToClusterAjax.success = function (response, status) {
              return Promise.resolve(
                Drupal.Ajax.prototype.success.call(addToClusterAjax, response, status),
              ).then(() => {
                if (status == "success" && response?.count !== undefined) {
                  const $clusterCounterBadge = document.querySelector("#strawberry-shortcake_ui-form-wrapper [data-drupal-strawberry-shortcake-counter=true]");
                  if ($clusterCounterBadge) {
                    $clusterCounterBadge.innerText = response.count;
                  }
                  if (response?.incurrentcluster !== undefined) {
                    const $label = event.target.parentNode.querySelector('label');
                    if (response.incurrentcluster == true) {
                      event.target.checked = true;
                      if ($label) {
                        $label.textContent = 'In Active Cluster';
                      }
                    }
                    else {
                      event.target.checked = false;
                      if ($label) {
                        $label.textContent = 'Add to Active Cluster';
                      }
                    }
                    if (Array.isArray(response?.otherclusters)) {
                      if ($label && response.otherclusters.length) {
                        $label.textContent = $label.textContent + ' (also in:' + response.otherclusters.join(", ") + ')';
                      }
                    }
                  }
                }
              });
            };
            addToClusterAjax.execute();

          });
          container.appendChild(label);
          container.appendChild(checkbox);
          element.parentNode.parentNode.prepend(container);
        }
      });
      // For those already executed...
      once.find('add-to-cluster').forEach((element) => {
        const $checkbox = document.getElementById(element.id + '-shortcake-check');
        if ($checkbox) {
          clusterMemberInfoUpdate($checkbox);
        }
      });
    }
  }
})(jQuery, Drupal, once);