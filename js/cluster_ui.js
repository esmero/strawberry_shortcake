(function ($, Drupal,  once) {

  'use strict';
  const clusterMemberInfoUpdate = function (element) {
    if (element.dataset.sbfClustersFlavorid) {
      const $endpoint_info = '/webannon/clustersbf/postgetinfo'
      const ajaxSettings = {
        data: {
          flavorid: element.dataset.sbfClustersFlavorid
        },
        dataType: 'json',
        url: '/do/' + element.dataset.sbfAnnotationsNodeuuid + $endpoint_info,
        type: 'POST',
      };

      // Make a Direct AJAX to avoid a race condition when we have so little
      // Cluster elements that once runs faster and we end calling this in an eternal loop
      // because drupal.ajax will re-trigger the behaviors even if we are just talking to an endpoint

      $.ajax(ajaxSettings).done(function(response) {
        if (response?.incurrentcluster !== undefined) {
          const $label = element.parentNode.querySelector('label');
          if (response.incurrentcluster == true) {
            element.checked = true;
            if ($label) {
              $label.textContent = 'In Active Cluster';
            }
          } else {
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
      }).fail(function(jqXHR, textStatus, errorThrown) {
        // This function is executed if the AJAX call encounters an error (rejected)
        console.error('Memberinfo request Failed:', textStatus, errorThrown);
      }).always(function() {
        // This function is always executed, regardless of success or failure
        console.log('Finished Calling memberInfo.');
      });
    }
  }

  Drupal.behaviors.strawberry_shortcake_activate = {
    attach: function (context, settings) {
      //const visible_annotations = document.querySelectorAll('[data-sbf-annotations-flavorid]');


      once('add-to-cluster','[data-sbf-annotations-flavorid]', context).forEach((element) => {
        if (element.dataset?.sbfAnnotationsFlavorid != undefined && element.dataset?.sbfAnnotationsNodeuuid != undefined) {
          const container = document.createElement('div')

          if (context == document) {
            console.log('Adding Clustering Checkboxes');
          }
          else {
            console.log('Adding Clustering Checkboxes when getting a context context');
          }

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
            const ajaxSettings = {
              data: {
                flavorid: event.target.dataset.sbfClustersFlavorid
              },
              dataType: 'json',
              url: '/do/' + event.target.dataset.sbfAnnotationsNodeuuid + $endpoint,
              type: 'POST',
            };
            // Make a Direct AJAX to avoid a race condition when we have so little
            // Cluster elements that once runs faster and we end calling this in an eternal loop
            // because drupal.ajax will re-trigger the behaviors even if we are just talking to an endpoint
            $.ajax(ajaxSettings).done(function(response) {
              if (response?.count !== undefined) {
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
            }}).fail(function(jqXHR, textStatus, errorThrown) {
              // This function is executed if the AJAX call encounters an error (rejected)
              console.error('Cluster Item Change request Failed:', textStatus, errorThrown);
            }).always(function() {
              // This function is always executed, regardless of success or failure
              console.log('Finished Cluster Change.');
            });

          });
          container.appendChild(label);
          container.appendChild(checkbox);
          element.parentNode.parentNode.prepend(container);
        }
      });
    }
  }

  /* @see \Drupal\strawberry_shortcake\Ajax\SbsBroadcastVectors */
  Drupal.AjaxCommands.prototype.SbsBroadCastVectors = function (ajax, response) {
    console.log(response.vectors);
    const vector_data = {
      "vectors":response.vectors,
    }
    const element = document.querySelector('body');
    console.log(JSON.stringify(vector_data).length);
    console.log(btoa(pako.gzip(JSON.stringify(vector_data))).length);
    console.log(pako.gzip(JSON.stringify(vector_data)).length);
    Drupal.FormatStrawberryfieldIiifUtils.dispatchImageViewChange(element, btoa(pako.gzip(JSON.stringify(vector_data))));
  }

  /* @see \Drupal\strawberry_shortcake\Ajax\SbsBroadcastVectors */
  Drupal.AjaxCommands.prototype.SbsUpdateClusterMemberInfo = function (ajax, response) {
    console.log(response.active_cluster);
    // For those already executed...
    once.find('add-to-cluster').forEach((element) => {
      const $checkbox = document.getElementById(element.id + '-shortcake-check');
      if ($checkbox) {
        clusterMemberInfoUpdate($checkbox);
      }
    });
  }

})(jQuery, Drupal, once);